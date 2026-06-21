<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Model\Entity\Reservation;
use Cake\Http\Exception\BadRequestException;

/**
 * Reservations — bookings plus the check-in/out/cancel lifecycle.
 *
 * The acting receptionist is stamped on `receptionist_id` at creation and on
 * every lifecycle transition, so the booking always shows who last handled it.
 */
class ReservationsController extends AppController
{
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
                ->limit(200)
        );

        $status = $this->request->getQuery('status');
        if ($status !== null) {
            $query->where(['Reservations.status' => $status]);
        }

        // Attach a price quote to each reservation.
        $rows = $query->all()->map(function (Reservation $r) use ($reservations) {
            $r->set('quote', $reservations->quote($r, $this->resolveBaseRate($r)));

            return $r;
        });

        $this->set('reservations', $rows->toList());
        $this->viewBuilder()->setOption('serialize', ['reservations']);
    }

    /**
     * POST /api/reservations
     *
     * { room_id, check_in, check_out, source?, discount_type?, promo_rate?,
     *   additional_beds?, guest_id? | guest_name?+nationality?+guest_type? }
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

                $reservation = $reservations->newEntity([
                    'property_id' => $propertyId,
                    'room_id' => $this->request->getData('room_id'),
                    'guest_id' => $guestId,
                    'receptionist_id' => (int)$this->currentUser->id,
                    'check_in' => $this->request->getData('check_in'),
                    'check_out' => $this->request->getData('check_out'),
                    'status' => 'booked',
                    'source' => $this->request->getData('source') ?? 'walk_in',
                    'discount_type' => $this->request->getData('discount_type') ?? 'none',
                    'promo_rate' => $this->request->getData('promo_rate'),
                    'additional_beds' => (int)($this->request->getData('additional_beds') ?? 0),
                ]);

                return $reservations->save($reservation) !== false;
            }
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
                $reservation->status
            ));
        }

        $reservations->getConnection()->transactional(function () use ($reservations, $reservation, $rule, $transition) {
            $reservation->set('status', $rule['to']);
            // Re-stamp: this receptionist is now the last to act on the booking.
            $reservation->set('receptionist_id', (int)$this->currentUser->id);
            // Log when the check-in/out *event* actually happened (distinct from
            // the planned check_in/check_out dates) for the Front Desk audit log.
            if ($transition === 'check-in') {
                $reservation->set('checked_in_at', new \Cake\I18n\DateTime());
            } elseif ($transition === 'check-out') {
                $reservation->set('checked_out_at', new \Cake\I18n\DateTime());
            }
            $reservations->saveOrFail($reservation);

            if ($reservation->room_id) {
                $rooms = $this->fetchTable('Rooms');
                $room = $rooms->get($reservation->room_id);
                $room->set('status', $rule['room']);
                $rooms->saveOrFail($room);
            }

            // On check-out, post the room charge to the guest's invoice so it
            // becomes collectable revenue (room revenue is otherwise computed
            // only at read-time and never persisted).
            if ($transition === 'check-out' && $reservation->guest_id) {
                $quote = $reservations->quote($reservation, $this->resolveBaseRate($reservation));
                if ($quote['total'] > 0) {
                    $invoices = $this->fetchTable('Invoices');
                    $invoice = $invoices->openInvoiceFor(
                        (int)$reservation->property_id,
                        (int)$reservation->guest_id,
                        (int)$reservation->id
                    );
                    $description = sprintf(
                        'Room %s · %d night(s)',
                        $reservation->room_id ? '#' . $reservation->room_id : '',
                        $quote['nights']
                    );
                    $invoices->addLine($invoice, $description, (float)$quote['total'], 'reservation', (int)$reservation->id);
                }
            }
        });

        $this->respondWithReservation($reservation, 200);
    }

    /**
     * Use an existing guest_id, or create a guest inline from guest_name.
     */
    private function resolveGuestId(int $propertyId): ?int
    {
        $guestId = $this->request->getData('guest_id');
        if ($guestId) {
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
     * Resolve the nightly base rate for a reservation: a room-specific rate if
     * one exists, else the cheapest property-wide rate, else 0.
     */
    private function resolveBaseRate(Reservation $reservation): float
    {
        $rates = $this->fetchTable('RoomRates');
        $rate = $rates->find()
            ->where(['RoomRates.property_id' => $reservation->property_id])
            ->where(function ($exp) use ($reservation) {
                return $exp->or([
                    'RoomRates.room_id' => $reservation->room_id,
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
        $full->set('quote', $reservations->quote($full, $this->resolveBaseRate($full)));

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
