<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Model\Entity\Reservation;
use App\Model\Table\BookingSourcesTable;
use App\Model\Table\ReservationsTable;
use Cake\Http\Exception\BadRequestException;
use Cake\I18n\Date;
use Cake\I18n\DateTime;

/**
 * Reservations — bookings plus the check-in/out/cancel lifecycle.
 *
 * The acting receptionist is stamped on `receptionist_id` at creation and on
 * every lifecycle transition, so the booking always shows who last handled it.
 */
class ReservationsController extends AppController
{
    /** Downpayment collected up front on an advance booking: 50% of the total. */
    private const DOWNPAYMENT_RATE = 0.5;

    /** Share of the downpayment retained when an advance booking is cancelled. */
    private const CANCELLATION_RETENTION = 0.1;

    /** Allowed status transitions and the room status each implies. */
    private const TRANSITIONS = [
        'check-in' => ['from' => ['booked'], 'to' => 'checked_in', 'room' => 'occupied'],
        'check-out' => ['from' => ['checked_in'], 'to' => 'checked_out', 'room' => 'available'],
        'cancel' => ['from' => ['booked', 'checked_in'], 'to' => 'cancelled', 'room' => 'available'],
    ];

    /**
     * GET /api/reservations[?status=booked]
     */
    public function index(): void
    {
        $reservations = $this->fetchTable('Reservations');
        $query = $this->scopeToProperty(
            $reservations->find()
                ->contain(['Rooms', 'Guests', 'Receptionist'])
                ->orderBy(['Reservations.check_in' => 'DESC'])
                ->limit(200),
        );

        $status = $this->request->getQuery('status');
        if ($status !== null) {
            $query->where(['Reservations.status' => $status]);
        }

        // Attach a price quote to each reservation.
        $rows = $query->all()->map(function (Reservation $r) use ($reservations) {
            $r->set('quote', $reservations->quote($r, $this->resolveBaseRate((int)$r->property_id, $r->room_id)));

            return $r;
        });

        $this->set('reservations', $rows->toList());
        $this->viewBuilder()->setOption('serialize', ['reservations']);
    }

    /**
     * POST /api/reservations
     *
     * { room_id, check_in, check_out, source?, discount_type?, discount_amount?,
     *   additional_beds?, guest_id? | guest_name?+nationality?+guest_type? }
     *
     * `discount_amount` (a flat peso amount, receptionist-decided) only applies
     * — and is only stored — when `discount_type` is `referral`; senior/pwd are
     * the fixed 20% statutory rate instead.
     *
     * The promo rate is resolved server-side from the promo_rates the admin
     * configured for the booking source — it is not accepted from the client.
     *
     * An advance booking (check-in after today) with a guest collects a 50%
     * downpayment of the quoted total, recorded as a settled invoice.
     */
    public function add(): void
    {
        $this->request->allowMethod('post');

        $propertyId = $this->effectivePropertyId();
        if ($propertyId === null) {
            throw new BadRequestException('property_id is required.');
        }

        $reservations = $this->fetchTable('Reservations');
        $reservation = null;

        // Rolls back the inline-created guest if the reservation fails to save.
        $ok = $reservations->getConnection()->transactional(
            function () use ($reservations, $propertyId, &$reservation): bool {
                $guestId = $this->resolveGuestId($propertyId);
                $source = $this->request->getData('source') ?? BookingSourcesTable::WALK_IN;
                $roomId = $this->request->getData('room_id');

                if (
                    $source !== BookingSourcesTable::WALK_IN
                    && !$this->fetchTable('BookingSources')->exists([
                        'BookingSources.property_id' => $propertyId,
                        'BookingSources.code' => $source,
                    ])
                ) {
                    throw new BadRequestException('Unknown booking source.');
                }

                // The promo rate is never client-supplied: it's the room's
                // original (base) rate × the admin's multiplier for the chosen
                // source (room-specific preferred), or null (base rate applies)
                // when no multiplier — or no base rate — is configured.
                $promoRate = null;
                if ($source !== BookingSourcesTable::WALK_IN) {
                    $multiplier = $this->fetchTable('PromoRates')
                        ->multiplierFor($propertyId, $source, $roomId ? (int)$roomId : null);
                    if ($multiplier !== null) {
                        $base = $this->resolveBaseRate($propertyId, $roomId ? (int)$roomId : null);
                        $promoRate = $base > 0 ? round($base * $multiplier, 2) : null;
                    }
                }

                $discountType = $this->request->getData('discount_type') ?? 'none';
                $reservation = $reservations->newEntity([
                    'property_id' => $propertyId,
                    'room_id' => $roomId,
                    'guest_id' => $guestId,
                    'receptionist_id' => (int)$this->currentUser->id,
                    'check_in' => $this->request->getData('check_in'),
                    'check_out' => $this->request->getData('check_out'),
                    'status' => 'booked',
                    'source' => $source,
                    'discount_type' => $discountType,
                    'discount_amount' => $discountType === 'referral' ? $this->request->getData('discount_amount') : null,
                    'promo_rate' => $promoRate,
                    'additional_beds' => (int)($this->request->getData('additional_beds') ?? 0),
                ]);

                if ($reservations->save($reservation) === false) {
                    return false;
                }

                // Advance booking (check-in after today): collect a 50%
                // downpayment of the quoted total (promo rate and senior/PWD
                // discount included) as an immediately-settled invoice, so it
                // shows in collections right away. Needs a guest to bill.
                if ($guestId !== null && $reservation->check_in > Date::today()) {
                    $quote = $reservations->quote(
                        $reservation,
                        $this->resolveBaseRate($propertyId, $roomId ? (int)$roomId : null),
                    );
                    $downpayment = round($quote['total'] * self::DOWNPAYMENT_RATE, 2);
                    if ($downpayment > 0) {
                        $reservation->set('downpayment', $downpayment);
                        $reservations->saveOrFail($reservation);
                        $this->fetchTable('Invoices')->settledInvoiceWith(
                            $propertyId,
                            (int)$guestId,
                            (int)$reservation->id,
                            sprintf('Downpayment (50%%) — booking #%d', $reservation->id),
                            $downpayment,
                            'downpayment',
                            (int)$reservation->id,
                        );
                    }
                }

                return true;
            },
        );

        if (!$ok) {
            $this->validationFailed($reservation->getErrors());

            return;
        }

        $this->respondWithReservation($reservation, 201);
    }

    /**
     * POST /api/reservations/{id}/{transition}  where transition is
     * check-in | check-out | cancel.
     */
    public function transition(int $id, string $transition): void
    {
        $this->request->allowMethod('post');

        if (!isset(self::TRANSITIONS[$transition])) {
            throw new BadRequestException('Unknown transition.');
        }
        $rule = self::TRANSITIONS[$transition];

        $reservations = $this->fetchTable('Reservations');
        $reservation = $this->scopeToProperty($reservations->find()->where(['Reservations.id' => $id]))
            ->contain(['Rooms'])
            ->firstOrFail();

        if (!in_array($reservation->status, $rule['from'], true)) {
            throw new BadRequestException(sprintf(
                'Cannot %s a reservation that is %s.',
                $transition,
                $reservation->status,
            ));
        }

        $fromStatus = $reservation->status;
        $reservations->getConnection()->transactional(function () use ($reservations, $reservation, $rule, $transition, $fromStatus) {
            $reservation->set('status', $rule['to']);
            // Re-stamp: this receptionist is now the last to act on the booking.
            $reservation->set('receptionist_id', (int)$this->currentUser->id);
            // Log when the check-in/out *event* actually happened (distinct from
            // the planned check_in/check_out dates) for the Front Desk audit log.
            if ($transition === 'check-in') {
                $reservation->set('checked_in_at', new DateTime());
            } elseif ($transition === 'check-out') {
                $reservation->set('checked_out_at', new DateTime());
            } elseif ($transition === 'cancel') {
                $reservation->set('cancelled_at', new DateTime());
            }
            $reservations->saveOrFail($reservation);

            if ($reservation->room_id) {
                $rooms = $this->fetchTable('Rooms');
                $room = $rooms->get($reservation->room_id);
                $room->set('status', $rule['room']);
                $rooms->saveOrFail($room);
            }

            // The room charge is usually already posted from Mark paid
            // (Front Desk) — postRoomCharge() is a no-op then. It still runs
            // here as a fallback for a reservation checked out without ever
            // being marked paid, so room revenue is always persisted by
            // check-out.
            if ($transition === 'check-out' && $reservation->guest_id) {
                $this->postRoomCharge($reservation);
            }

            // Early check-in: the receptionist confirmed an early arrival, so
            // bill the configured fee to the guest's invoice.
            if (
                $transition === 'check-in'
                && $this->request->getData('early_check_in')
                && $reservation->guest_id
            ) {
                $extraCharges = $this->fetchTable('ExtraCharges');
                $charge = $extraCharges->earlyCheckInFor((int)$reservation->property_id);
                $fee = $charge->is_active ? (float)$charge->amount : 0.0;
                if ($fee > 0) {
                    $invoices = $this->fetchTable('Invoices');
                    $invoice = $invoices->openInvoiceFor(
                        (int)$reservation->property_id,
                        (int)$reservation->guest_id,
                        (int)$reservation->id,
                    );
                    $invoices->addLine($invoice, 'Early check-in', $fee, 'early_check_in', (int)$reservation->id);
                }
            }

            // Cancelling reverses any early check-in fee, room charge, and
            // downpayment credit already posted from Mark paid ahead of
            // check-out (a cancelled booking shouldn't leave any of those on
            // the guest's tab).
            if ($transition === 'cancel') {
                $invoices = $this->fetchTable('Invoices');
                $invoices->removeLinesFor('early_check_in', (int)$reservation->id);
                $invoices->removeLinesFor('reservation', (int)$reservation->id);
                $invoices->removeLinesFor('downpayment_credit', (int)$reservation->id);

                // A cancelled advance booking doesn't get the downpayment back
                // in full: 10% is retained, 90% is refunded onto the settled
                // downpayment invoice (its total drops to the retained share,
                // which is what stays in collections).
                $downpayment = (float)$reservation->downpayment;
                if ($fromStatus === 'booked' && $downpayment > 0) {
                    $invoice = $invoices->invoiceForLine('downpayment', (int)$reservation->id);
                    if ($invoice !== null) {
                        $refund = round($downpayment * (1 - self::CANCELLATION_RETENTION), 2);
                        $invoices->addLine(
                            $invoice,
                            'Downpayment refund on cancellation (10% retained)',
                            -$refund,
                            'downpayment_refund',
                            (int)$reservation->id,
                        );
                    }
                }
            }
        });

        $this->respondWithReservation($reservation, 200);
    }

    /**
     * POST /api/reservations/{id}/payment  { payment_status: unpaid|paid }
     *
     * A Front Desk operational flag the receptionist toggles once the guest
     * has settled up — independent of the booking lifecycle and of the
     * invoice's own settled status (Food & Orders → Invoices).
     *
     * Marking a reservation `paid` opens (or reuses) the guest's invoice right
     * away, ahead of check-out, and posts the room charge onto it immediately
     * (instead of only at check-out) — so the amount is visible on Food &
     * Orders → Invoices as soon as the guest has settled up, whether that
     * happens at check-in or any time before check-out. Any extras ordered
     * afterwards (additional linens, food) land on that same open tab via
     * `openInvoiceFor()`'s find-or-create-by-guest lookup.
     */
    public function payment(int $id): void
    {
        $this->request->allowMethod('post');

        $reservations = $this->fetchTable('Reservations');
        $reservation = $this->scopeToProperty($reservations->find()->where(['Reservations.id' => $id]))
            ->firstOrFail();

        $status = $this->request->getData('payment_status');
        if (!in_array($status, ReservationsTable::PAYMENT_STATUSES, true)) {
            throw new BadRequestException('payment_status must be unpaid or paid.');
        }

        $reservations->getConnection()->transactional(function () use ($reservations, $reservation, $status) {
            $reservation->set('payment_status', $status);
            $reservations->saveOrFail($reservation);

            if ($status === 'paid' && $reservation->guest_id) {
                $this->fetchTable('Invoices')->openInvoiceFor(
                    (int)$reservation->property_id,
                    (int)$reservation->guest_id,
                    (int)$reservation->id,
                );
                $this->postRoomCharge($reservation);
            }
        });

        $this->respondWithReservation($reservation, 200);
    }

    /**
     * Post the room charge — subtotal plus any senior/PWD discount as its own
     * negative line — to the guest's invoice, itemized the same way whether
     * it's triggered by Mark paid or by check-out. Idempotent: a no-op if a
     * `reservation`-sourced line already exists for this booking, so whichever
     * of the two happens first is the one that posts it.
     *
     * If the booking took a downpayment, its credit is posted in the same
     * call — right alongside the charge it offsets, not deferred to
     * check-out — so the open tab never briefly shows the full 100% on top
     * of a downpayment already collected. The credit only ever posts once
     * its offsetting charge line exists (this call or an earlier one) and
     * has its own idempotency check on top of that.
     *
     * Callable from both payment() (Mark paid) and transition() (check-out),
     * so two near-simultaneous calls for the same reservation (a Mark-paid
     * double-click, a retried request) could otherwise both pass the
     * invoiceForLine() checks before either's insert commits and double-post
     * a line. The row lock below serializes them, mirroring
     * ReceiptSeriesTable::assignNext()'s FOR UPDATE for the same "read then
     * decide to insert" problem — both callers already run inside a
     * transaction, so the lock holds until that transaction commits.
     */
    private function postRoomCharge(Reservation $reservation): void
    {
        if (!$reservation->guest_id) {
            return;
        }

        $this->fetchTable('Reservations')->find()
            ->where(['id' => $reservation->id])
            ->epilog('FOR UPDATE')
            ->firstOrFail();

        $invoices = $this->fetchTable('Invoices');
        $invoice = null;
        $hasChargeLine = $invoices->invoiceForLine('reservation', (int)$reservation->id) !== null;

        if (!$hasChargeLine) {
            $quote = $this->fetchTable('Reservations')->quote(
                $reservation,
                $this->resolveBaseRate((int)$reservation->property_id, $reservation->room_id),
            );

            if ($quote['subtotal'] > 0) {
                $invoice = $invoices->openInvoiceFor(
                    (int)$reservation->property_id,
                    (int)$reservation->guest_id,
                    (int)$reservation->id,
                );

                $rateNote = $reservation->promo_rate !== null
                    ? sprintf(
                        ' (%s promo rate)',
                        $this->fetchTable('BookingSources')->labelFor(
                            (int)$reservation->property_id,
                            $reservation->source,
                        ),
                    )
                    : '';
                $description = sprintf(
                    'Room %s · %d night(s)%s',
                    $reservation->room_id ? '#' . $reservation->room_id : '',
                    $quote['nights'],
                    $rateNote,
                );
                $invoices->addLine(
                    $invoice,
                    $description,
                    (float)$quote['subtotal'],
                    'reservation',
                    (int)$reservation->id,
                );

                if ($quote['discount'] > 0) {
                    $description = match ($reservation->discount_type) {
                        'senior' => 'Senior discount (20%)',
                        'pwd' => 'PWD discount (20%)',
                        default => 'Referral discount',
                    };
                    $invoices->addLine(
                        $invoice,
                        $description,
                        -(float)$quote['discount'],
                        'reservation',
                        (int)$reservation->id,
                    );
                }
                $hasChargeLine = true;
            }
        }

        // Only credit the downpayment once its offsetting charge actually
        // exists on the invoice (just posted above, or already posted by an
        // earlier call) — never on its own. If the room's rate can't be
        // resolved right now (quote subtotal 0, e.g. an edited/removed rate),
        // skip the credit too rather than leave it stranded with nothing to
        // offset; it posts once a later call succeeds in posting the charge.
        $downpayment = (float)$reservation->downpayment;
        if (
            $hasChargeLine
            && $downpayment > 0
            && $invoices->invoiceForLine('downpayment_credit', (int)$reservation->id) === null
        ) {
            $invoice ??= $invoices->openInvoiceFor(
                (int)$reservation->property_id,
                (int)$reservation->guest_id,
                (int)$reservation->id,
            );
            // The downpayment was already collected at booking (its own
            // settled invoice) — credit it here so the open tab only ever
            // carries the balance.
            $invoices->addLine(
                $invoice,
                'Less: downpayment already collected',
                -$downpayment,
                'downpayment_credit',
                (int)$reservation->id,
            );
        }
    }

    /**
     * Use an existing guest_id, or create a guest inline from guest_name.
     */
    private function resolveGuestId(int $propertyId): ?int
    {
        $guestId = $this->request->getData('guest_id');
        if ($guestId) {
            $this->completeGuest((int)$guestId, $propertyId);

            return (int)$guestId;
        }

        $name = trim((string)$this->request->getData('guest_name'));
        if ($name === '') {
            return null;
        }

        $guests = $this->fetchTable('Guests');
        $guest = $guests->newEntity([
            'property_id' => $propertyId,
            'full_name' => $name,
            'nationality' => $this->request->getData('nationality'),
            'address' => $this->request->getData('address'),
            'contact_number' => $this->request->getData('contact_number'),
            'email' => $this->request->getData('email'),
            'guest_type' => $this->request->getData('guest_type') ?? 'local',
        ]);
        $guests->saveOrFail($guest);

        return (int)$guest->id;
    }

    /**
     * Fill in any *empty* detail fields on an existing guest from the booking
     * form. Re-booking a returning guest can thus complete a sparse record
     * (add a missing contact number, email, etc.) without ever overwriting
     * information already on file.
     */
    private function completeGuest(int $guestId, int $propertyId): void
    {
        $guests = $this->fetchTable('Guests');
        $guest = $guests->find()
            ->where(['Guests.id' => $guestId, 'Guests.property_id' => $propertyId])
            ->first();
        if ($guest === null) {
            return;
        }

        $changed = false;
        foreach (['nationality', 'address', 'contact_number', 'email'] as $field) {
            $incoming = trim((string)$this->request->getData($field));
            if ($incoming !== '' && trim((string)$guest->get($field)) === '') {
                $guest->set($field, $incoming);
                $changed = true;
            }
        }
        if ($changed) {
            $guests->saveOrFail($guest);
        }
    }

    /**
     * Resolve the nightly base rate for a room: a room-specific rate if
     * one exists, else the cheapest property-wide rate, else 0.
     */
    private function resolveBaseRate(int $propertyId, ?int $roomId): float
    {
        $rates = $this->fetchTable('RoomRates');
        $rate = $rates->find()
            ->where(['RoomRates.property_id' => $propertyId])
            ->where(function ($exp) use ($roomId) {
                return $exp->or([
                    'RoomRates.room_id' => $roomId,
                    'RoomRates.room_id IS' => null,
                ]);
            })
            // Prefer a room-specific rate over a property-wide one.
            ->orderBy(['RoomRates.room_id' => 'DESC', 'RoomRates.base_rate' => 'ASC'])
            ->first();

        return $rate ? (float)$rate->base_rate : 0.0;
    }

    private function respondWithReservation(Reservation $reservation, int $status): void
    {
        $reservations = $this->fetchTable('Reservations');
        $full = $reservations->get($reservation->id, contain: ['Rooms', 'Guests', 'Receptionist']);
        $full->set('quote', $reservations->quote($full, $this->resolveBaseRate((int)$full->property_id, $full->room_id)));

        $this->response = $this->response->withStatus($status);
        $this->set('reservation', $full);
        $this->viewBuilder()->setOption('serialize', ['reservation']);
    }

    private function validationFailed(array $errors): void
    {
        $this->response = $this->response->withStatus(422);
        $this->set('errors', $errors);
        $this->viewBuilder()->setOption('serialize', ['errors']);
    }
}
