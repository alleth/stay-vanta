<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Model\BusinessTime;
use App\Model\Entity\Reservation;
use App\Model\StatutoryDiscount;
use App\Model\Table\BookingSourcesTable;
use App\Model\Table\ReservationExtraChargesTable;
use App\Model\Table\ReservationsTable;
use Cake\Database\Expression\QueryExpression;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\ForbiddenException;
use Cake\I18n\Date;
use Cake\I18n\DateTime;
use Cake\ORM\Query\SelectQuery;

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
     * GET /api/reservations[?status=][?payment_status=][?since=YYYY-MM-DD][?on_date=YYYY-MM-DD][?page=&limit=]
     *   → {reservations, total, page, limit}
     *
     * - `since`: the Front Desk table's "fresh start" window — stays still in
     *   play (booked / checked in) always show, finished ones (checked out /
     *   cancelled) only if that happened on or after the date. Omit for all.
     * - `on_date`: the Calendar tab — non-cancelled stays touching the date,
     *   arrival and departure days included.
     * - `limit` pages the result (clamped 5–100) only when a caller passes it;
     *   omitting it returns the same wide window (200) the endpoint always did,
     *   which is what Food & Orders' checked-in picker relies on.
     */
    public function index(): void
    {
        $reservations = $this->fetchTable('Reservations');
        $query = $this->scopeToProperty(
            $reservations->find()
                // ReservationDiscounts rides along because quote() prices from
                // it — without it every row would cost one extra query.
                ->contain(['Rooms', 'Guests', 'Receptionist', 'ReservationDiscounts', 'ReservationExtraCharges'])
                // id breaks ties so a page boundary never repeats or skips a row.
                ->orderBy(['Reservations.check_in' => 'DESC', 'Reservations.id' => 'DESC']),
        );

        $status = $this->request->getQuery('status');
        if ($status !== null) {
            $query->where(['Reservations.status' => $status]);
        }

        // `payment_status=unpaid` is the Unpaid card's list: the same set it
        // counts (see stats()), so a cancelled booking never shows as owing.
        $paymentStatus = $this->request->getQuery('payment_status');
        if ($paymentStatus !== null && $paymentStatus !== '') {
            if (!in_array($paymentStatus, ReservationsTable::PAYMENT_STATUSES, true)) {
                throw new BadRequestException('payment_status must be unpaid or paid.');
            }
            $query->where(['Reservations.payment_status' => $paymentStatus]);
            if ($paymentStatus === 'unpaid') {
                $query->where(['Reservations.status !=' => 'cancelled']);
            }
        }

        // `billing=not_billed`: the Front Desk "not billed" figure and Finance →
        // Receivables — the same set stats() counts (see notBilled()).
        $billing = $this->request->getQuery('billing');
        if ($billing !== null && $billing !== '') {
            if ($billing !== 'not_billed') {
                throw new BadRequestException('billing must be not_billed.');
            }
            $this->notBilled($query);
        }

        $since = $this->queryDate('since');
        if ($since !== null) {
            $from = BusinessTime::startOf($since);
            $query->where(['OR' => [
                'Reservations.status IN' => ReservationsTable::HOLDS_ROOM,
                ['Reservations.status' => 'checked_out', 'Reservations.checked_out_at >=' => $from],
                ['Reservations.status' => 'cancelled', 'Reservations.cancelled_at >=' => $from],
            ]]);
        }

        $onDate = $this->queryDate('on_date');
        if ($onDate !== null) {
            $query->where([
                'Reservations.status !=' => 'cancelled',
                'Reservations.check_in <=' => $onDate,
                'Reservations.check_out >=' => $onDate,
            ]);
        }

        $total = $query->count();
        $requestedLimit = $this->request->getQuery('limit');
        $limit = $requestedLimit !== null ? min(100, max(5, (int)$requestedLimit)) : 200;
        $page = max(1, (int)($this->request->getQuery('page') ?? 1));
        $query->limit($limit)->offset(($page - 1) * $limit);

        $rows = $query->all()->toList();
        $chargeStatus = $this->roomChargeStatuses(array_map(fn(Reservation $r): int => (int)$r->id, $rows));

        // Attach a price quote to each reservation, and where its room charge
        // stands: null (not posted yet), 'open' (on the guest's tab, not yet
        // collected) or 'settled' (collected).
        foreach ($rows as $r) {
            $r->set('quote', $reservations->quote($r, $this->resolveBaseRate((int)$r->property_id, $r->room_id)));
            $r->set('room_charge_invoice', $chargeStatus[(int)$r->id] ?? null);
            $r->set('billing_state', self::billingState($chargeStatus[(int)$r->id] ?? null));
        }

        $this->set([
            'reservations' => $rows,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ]);
        $this->viewBuilder()->setOption('serialize', ['reservations', 'total', 'page', 'limit']);
    }

    /**
     * GET /api/reservations/stats → {booked, checked_out_today, cancelled_today, unpaid, open_invoices}
     *
     * The Front Desk summary cards, counted in the database rather than from
     * whatever page of reservations the table happens to have loaded.
     *
     * `unpaid` is every reservation still marked unpaid that isn't cancelled —
     * a stay already checked out without being paid is exactly the one to
     * chase, so it counts too.
     */
    public function stats(): void
    {
        $reservations = $this->fetchTable('Reservations');
        $today = BusinessTime::today()->format('Y-m-d');
        $count = fn(array $where): int => $this->scopeToProperty($reservations->find()->where($where))->count();

        $this->set([
            'booked' => $count(['Reservations.status' => 'booked']),
            'checked_out_today' => $count([
                'Reservations.status' => 'checked_out',
                'Reservations.checked_out_at >=' => BusinessTime::startOf($today),
            ]),
            'cancelled_today' => $count([
                'Reservations.status' => 'cancelled',
                'Reservations.cancelled_at >=' => BusinessTime::startOf($today),
            ]),
            // Old figure, kept for older screens until step 3 settles; new
            // screens show not_billed.
            'unpaid' => $count([
                'Reservations.status !=' => 'cancelled',
                'Reservations.payment_status' => 'unpaid',
            ]),
            'not_billed' => $this->notBilled($this->scopeToProperty($reservations->find()))->count(),
            // Every open invoice (room charges, food charged to the room…):
            // money charged but not yet collected until someone settles it.
            'open_invoices' => $this->scopeToProperty(
                $this->fetchTable('Invoices')->find()->where(['Invoices.status' => 'open']),
            )->count(),
        ]);
        $this->viewBuilder()->setOption(
            'serialize',
            ['booked', 'checked_out_today', 'cancelled_today', 'unpaid', 'not_billed', 'open_invoices'],
        );
    }

    /**
     * A reservation's billing state, read from the invoice its room charge
     * sits on (the source of truth, not `payment_status`):
     * not_billed (no room charge posted) · billed (on an open invoice) ·
     * settled (that invoice is settled — collected).
     */
    private static function billingState(?string $chargeInvoiceStatus): string
    {
        return match ($chargeInvoiceStatus) {
            'settled' => 'settled',
            'open' => 'billed',
            default => 'not_billed',
        };
    }

    /**
     * Narrow a reservations query to stays that should be billed but aren't:
     * the stay has started (checked in or out) and no room charge is posted.
     * Future bookings aren't receivables yet, so they're not in it. A
     * correlated NOT EXISTS, not a GROUP BY (ONLY_FULL_GROUP_BY on MySQL 9).
     */
    private function notBilled(SelectQuery $query): SelectQuery
    {
        $chargeLines = $this->fetchTable('InvoiceLines')->find()
            ->select(['InvoiceLines.id'])
            ->where([
                'InvoiceLines.source_type' => 'reservation',
                'InvoiceLines.source_id = Reservations.id',
            ]);

        return $query
            ->where(['Reservations.status IN' => ['checked_in', 'checked_out']])
            ->where(fn(QueryExpression $exp) => $exp->notExists($chargeLines));
    }

    /**
     * The status of the invoice each reservation's room charge sits on, keyed
     * by reservation id — one query for the whole page. The money counts as
     * collected once that invoice is settled, so the table needs this to show
     * which billed stays are still sitting on an open invoice.
     *
     * @param list<int> $ids
     * @return array<int, string> reservation id → 'open' | 'settled'
     */
    private function roomChargeStatuses(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->fetchTable('InvoiceLines')->find()
            ->select(['source_id', 'status' => 'Invoices.status'])
            ->innerJoinWith('Invoices')
            ->where(['InvoiceLines.source_type' => 'reservation', 'InvoiceLines.source_id IN' => $ids])
            ->disableHydration()
            ->all();

        $statuses = [];
        foreach ($rows as $row) {
            $statuses[(int)$row['source_id']] = (string)$row['status'];
        }

        return $statuses;
    }

    /**
     * Whether the invoice carrying this reservation's room charge has been
     * settled — the money collected and its SI/OR numbers issued. From then
     * on the reservation is part of the books: it can't be edited, deleted or
     * marked unpaid.
     */
    private function isSettled(Reservation $reservation): bool
    {
        $invoice = $this->fetchTable('Invoices')->invoiceForLine('reservation', (int)$reservation->id);

        return $invoice !== null && $invoice->status === 'settled';
    }

    /**
     * A `Y-m-d` query parameter, or null when it's absent.
     *
     * @throws \Cake\Http\Exception\BadRequestException When it's present but malformed.
     */
    private function queryDate(string $name): ?string
    {
        $value = $this->request->getQuery($name);
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw new BadRequestException("{$name} must be a YYYY-MM-DD date.");
        }

        return $value;
    }

    /**
     * POST /api/reservations
     *
     * { room_id, check_in, check_out, source?, total_guests?,
     *   discount_beneficiaries?, discount_amount?, additional_beds?,
     *   channel_discount_type?, channel_discount_value?,
     *   guest_id? | guest_name?+nationality?+guest_type? }
     *
     * `channel_discount_type` (percent | fixed) + `channel_discount_value` is
     * a promotion the online channel gave the guest — ignored for a walk-in.
     *
     * `discount_beneficiaries[]` ({discount_type: senior|pwd, name, id_number})
     * is the Senior/PWD side: a room can hold several qualified guests, each
     * recorded with their own ID, and the statutory 20% only covers each one's
     * share of `total_guests` (see ReservationsTable::quote()).
     * `discount_amount` (a flat peso referral amount, receptionist-decided) is
     * independent and stacks — a guest can be a senior citizen *and* referred.
     *
     * The promo rate is resolved server-side from the promo_rates the admin
     * configured for the booking source — it is not accepted from the client.
     *
     * An advance booking (check-in after today) with a guest collects a 50%
     * downpayment of the quoted total, recorded as a settled invoice.
     *
     * A check-in before today records a stay that already happened, and only
     * an admin may add one (see isBackdated()). Its status follows the dates:
     * checked out if it ended on or before today, checked in (room occupied)
     * if it's still going.
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

        // Parsed before the transaction opens: a malformed beneficiary is the
        // caller's mistake, not a reason to have started writing rows.
        $totalGuests = $this->resolveTotalGuests();
        $beneficiaries = $this->parseBeneficiaries($totalGuests);
        $extras = $this->parseExtras($propertyId) ?? [];
        $backdated = $this->isBackdated(
            $this->request->getData('source') ?? BookingSourcesTable::WALK_IN,
            $this->request->getData('check_in'),
        );

        // Rolls back the inline-created guest if the reservation fails to save.
        $ok = $reservations->getConnection()->transactional(
            function () use (
                $reservations,
                $propertyId,
                $totalGuests,
                $beneficiaries,
                $extras,
                $backdated,
                &$reservation,
            ): bool {
                $guestId = $this->resolveGuestId($propertyId);
                $source = $this->request->getData('source') ?? BookingSourcesTable::WALK_IN;
                $roomId = $this->request->getData('room_id');

                // Serialise bookings for this room so two receptionists can't
                // both pass the availability rule before either has saved.
                if ($roomId) {
                    $reservations->lockRoom((int)$roomId);
                }

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

                // A walk-in is a guest standing at the desk, so it isn't a
                // future booking at all: the stay starts today and they take
                // the room on save, rather than being booked and then checked
                // in a moment later. The date is decided here rather than
                // trusted from the form — "walk-in" and "arriving next week"
                // can't both be true. A backdated stay is the exception: an
                // admin recording a past walk-in keeps the date they entered.
                $isWalkIn = $source === BookingSourcesTable::WALK_IN;
                $channelDiscount = $this->resolveChannelDiscount($source);
                $checkIn = $isWalkIn && !$backdated
                    ? BusinessTime::today()->format('Y-m-d')
                    : $this->request->getData('check_in');
                $checkOut = $this->request->getData('check_out');

                // A past stay is saved as whatever its dates say it is by now,
                // with the event times taken from those dates rather than from
                // the moment it was typed in — so it doesn't count toward
                // today's check-ins/check-outs.
                $status = $isWalkIn ? 'checked_in' : 'booked';
                $checkedInAt = $isWalkIn ? new DateTime() : null;
                $checkedOutAt = null;
                if ($backdated) {
                    $ended = is_string($checkOut) && $checkOut <= BusinessTime::today()->format('Y-m-d');
                    $status = $ended ? 'checked_out' : 'checked_in';
                    $checkedInAt = BusinessTime::midnightOf($checkIn);
                    $checkedOutAt = $ended ? BusinessTime::midnightOf($checkOut) : null;
                }

                $reservation = $reservations->newEntity([
                    'property_id' => $propertyId,
                    'room_id' => $roomId,
                    'guest_id' => $guestId,
                    'receptionist_id' => (int)$this->currentUser->id,
                    'check_in' => $checkIn,
                    'check_out' => $checkOut,
                    'status' => $status,
                    'checked_in_at' => $checkedInAt,
                    'checked_out_at' => $checkedOutAt,
                    'source' => $source,
                    // Both belong to the channel, so a walk-in never carries
                    // them however the form was filled in before the type was
                    // switched.
                    'booking_reference' => $isWalkIn ? null : $this->trimmedOrNull('booking_reference'),
                    'sold_rate' => $isWalkIn ? null : $this->trimmedOrNull('sold_rate'),
                    'channel_discount_type' => $channelDiscount['type'],
                    'channel_discount_value' => $channelDiscount['value'],
                    'total_guests' => $totalGuests,
                    'discount_amount' => $this->resolveReferralAmount(),
                    'promo_rate' => $promoRate,
                    'additional_beds' => (int)($this->request->getData('additional_beds') ?? 0),
                ]);

                if ($reservations->save($reservation) === false) {
                    return false;
                }

                // Before the downpayment below: it quotes the booking, and the
                // quote prices from these.
                $this->saveBeneficiaries($reservation, $beneficiaries);
                $this->saveExtras($reservation, $extras);

                // The room is taken from this moment, exactly as transition()
                // does on check-in. No early check-in fee: that charge is for
                // arriving ahead of a booked date, which a walk-in has none of,
                // and there is no confirmation step here to ask about it. A
                // past stay that has already ended leaves the room as it is.
                if ($status === 'checked_in' && $reservation->room_id) {
                    $rooms = $this->fetchTable('Rooms');
                    $room = $rooms->get($reservation->room_id);
                    $room->set('status', 'occupied');
                    $rooms->saveOrFail($room);
                }

                // Only an advance booking takes a downpayment, so this is a
                // no-op for a walk-in — its stay starts today.
                if ($guestId !== null) {
                    $this->collectAdvanceDownpayment($reservation, $propertyId, $guestId);
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
     * PATCH/PUT /api/reservations/{id} — fix a mistake made at booking (wrong
     * room, dates, source, discount) while the guest hasn't checked in yet.
     * Any authed staff may edit (matching who can create a booking).
     *
     * Blocked once checked in, and blocked once a downpayment has been
     * collected against the original quote — editing the total afterward
     * would leave that already-collected amount out of sync with a
     * recalculated one; cancel and rebook instead (cancellation already
     * refunds 90% of the downpayment correctly).
     *
     * Accepts the same body as add() (room_id, check_in, check_out, source?,
     * total_guests?, discount_beneficiaries?, discount_amount?,
     * additional_beds?) minus the guest fields — the linked guest isn't
     * editable here. Sending `discount_beneficiaries` replaces the booking's
     * beneficiaries wholesale, the way FoodMenuItemsController::saveIngredients()
     * treats a recipe; omitting the key leaves them as they are. The promo rate is
     * recomputed server-side the same way add() does, for whatever
     * source/room the edit ends up with. If the edit turns this into (or
     * keeps it as) an advance booking, the downpayment is collected the same
     * way add() does.
     *
     * An admin may also correct a stay that's already checked in or out (a
     * past stay they recorded, say) — until its room charge has been posted,
     * since the invoice would then disagree with the corrected booking. The
     * status stays as it is, so the dates must keep agreeing with it, and the
     * check-in/out event dates move with the stay's own dates.
     */
    public function edit(int $id): void
    {
        $this->request->allowMethod(['patch', 'put', 'post']);

        $propertyId = $this->effectivePropertyId();
        if ($propertyId === null) {
            throw new BadRequestException('property_id is required.');
        }

        $reservations = $this->fetchTable('Reservations');
        $reservation = $this->scopeToProperty($reservations->find()->where(['Reservations.id' => $id]))
            ->firstOrFail();

        if ($this->isSettled($reservation)) {
            throw new BadRequestException(
                "This reservation's invoice is already settled, so it can no longer be edited.",
            );
        }
        $isStay = in_array($reservation->status, ['checked_in', 'checked_out'], true);
        if ($reservation->status !== 'booked' && !($isStay && $this->userHasRole('admin'))) {
            throw new BadRequestException("Only a booking that hasn't checked in yet can be edited.");
        }
        if ((float)$reservation->downpayment > 0) {
            throw new BadRequestException(
                'This booking already collected a downpayment — cancel and rebook instead of editing it.',
            );
        }
        if ($isStay && $this->fetchTable('Invoices')->invoiceForLine('reservation', (int)$reservation->id)) {
            throw new BadRequestException(
                "This stay's room charge is already on the guest's invoice, so it can no longer be edited.",
            );
        }

        $source = $this->request->getData('source') ?? $reservation->source;
        if (
            $source !== BookingSourcesTable::WALK_IN
            && !$this->fetchTable('BookingSources')->exists([
                'BookingSources.property_id' => $propertyId,
                'BookingSources.code' => $source,
            ])
        ) {
            throw new BadRequestException('Unknown booking source.');
        }

        // Moving the check-in into the past is the same privilege as booking
        // there; a date left as it was (a no-show still on the books) isn't.
        $newCheckIn = $this->request->getData('check_in');
        if (
            $newCheckIn !== null
            && $newCheckIn !== $reservation->check_in?->format('Y-m-d')
            && $this->isBeforeToday($newCheckIn)
            && !$this->userHasRole('admin')
        ) {
            throw new ForbiddenException('Only a Manager can move a booking to a date before today.');
        }

        $roomId = $this->request->getData('room_id') ?? $reservation->room_id;
        $channelDiscount = $this->resolveChannelDiscount($source, $reservation);

        // The promo rate is never client-supplied: recomputed the same way
        // add() does, for the (possibly new) source/room.
        $promoRate = null;
        if ($source !== BookingSourcesTable::WALK_IN) {
            $multiplier = $this->fetchTable('PromoRates')
                ->multiplierFor($propertyId, $source, $roomId ? (int)$roomId : null);
            if ($multiplier !== null) {
                $base = $this->resolveBaseRate($propertyId, $roomId ? (int)$roomId : null);
                $promoRate = $base > 0 ? round($base * $multiplier, 2) : null;
            }
        }

        // Omitting the key leaves the booking's beneficiaries alone; sending
        // it (even empty, to clear them) replaces the lot.
        $replaceBeneficiaries = $this->request->getData('discount_beneficiaries') !== null;
        $totalGuests = $this->resolveTotalGuests((int)($reservation->total_guests ?? 1));
        $beneficiaries = $replaceBeneficiaries ? $this->parseBeneficiaries($totalGuests) : [];
        // Null (key not sent) leaves the booking's extra charges as they are.
        $extras = $this->parseExtras($propertyId, $reservation);
        if (!$replaceBeneficiaries) {
            // The guest count can still be edited on its own, and shrinking it
            // below the beneficiaries already on file would price the discount
            // above the statutory rate.
            $existing = count($this->fetchTable('Reservations')->beneficiariesFor($reservation));
            if ($existing > $totalGuests) {
                throw new BadRequestException(
                    'This booking already has more Senior/PWD beneficiaries than that many guests.',
                );
            }
        }

        // Transactional for the same reason add() is: lockRoom() only holds
        // for the life of a transaction, and moving a booking to a different
        // room (or different dates) races exactly like creating one.
        $saved = $reservations->getConnection()->transactional(
            function () use (
                $reservations,
                $reservation,
                $propertyId,
                $roomId,
                $source,
                $promoRate,
                $channelDiscount,
                $totalGuests,
                $beneficiaries,
                $replaceBeneficiaries,
                $extras,
            ): bool {
                if ($roomId) {
                    $reservations->lockRoom((int)$roomId);
                }

                $reservations->patchEntity($reservation, [
                    'room_id' => $roomId,
                    'check_in' => $this->request->getData('check_in') ?? $reservation->check_in,
                    'check_out' => $this->request->getData('check_out') ?? $reservation->check_out,
                    'source' => $source,
                    'booking_reference' => $source === BookingSourcesTable::WALK_IN
                        ? null
                        : $this->trimmedOrNull('booking_reference') ?? $reservation->booking_reference,
                    'total_guests' => $totalGuests,
                    'discount_amount' => $this->resolveReferralAmount(),
                    'promo_rate' => $promoRate,
                    'sold_rate' => $source === BookingSourcesTable::WALK_IN
                        ? null
                        : $this->trimmedOrNull('sold_rate') ?? $reservation->sold_rate,
                    'channel_discount_type' => $channelDiscount['type'],
                    'channel_discount_value' => $channelDiscount['value'],
                    'additional_beds' => (int)($this->request->getData('additional_beds')
                        ?? $reservation->additional_beds),
                    'receptionist_id' => (int)$this->currentUser->id,
                ], ['accessibleFields' => ['property_id' => false]]);

                $previousRoomId = $reservation->getOriginal('room_id');
                if ($reservation->status !== 'booked') {
                    $this->correctStay($reservation);
                }

                if ($reservations->save($reservation) === false) {
                    return false;
                }

                if ($replaceBeneficiaries) {
                    $this->saveBeneficiaries($reservation, $beneficiaries);
                }
                if ($extras !== null) {
                    $this->saveExtras($reservation, $extras);
                }

                // A guest still in the room moves with the correction.
                if (
                    $reservation->status === 'checked_in'
                    && (int)$previousRoomId !== (int)$reservation->room_id
                ) {
                    $rooms = $this->fetchTable('Rooms');
                    foreach ([$previousRoomId => 'available', $reservation->room_id => 'occupied'] as $rid => $status) {
                        if ($rid) {
                            $room = $rooms->get($rid);
                            $room->set('status', $status);
                            $rooms->saveOrFail($room);
                        }
                    }
                }

                if ($reservation->guest_id !== null) {
                    $this->collectAdvanceDownpayment($reservation, $propertyId, (int)$reservation->guest_id);
                }

                return true;
            },
        );

        if (!$saved) {
            $this->validationFailed($reservation->getErrors());

            return;
        }

        $this->respondWithReservation($reservation, 200);
    }

    /**
     * Keep an edited checked-in/out stay consistent with its status: the
     * dates may not say it hasn't happened yet, and the recorded check-in/out
     * moments follow their dates (keeping the time of day they carried).
     *
     * @throws \Cake\Http\Exception\BadRequestException When the dates contradict the status.
     */
    private function correctStay(Reservation $reservation): void
    {
        $today = BusinessTime::today();
        if ($reservation->check_in instanceof Date && $reservation->check_in->greaterThan($today)) {
            throw new BadRequestException("A stay that's already begun can't start after today.");
        }
        if (
            $reservation->status === 'checked_out'
            && $reservation->check_out instanceof Date
            && $reservation->check_out->greaterThan($today)
        ) {
            throw new BadRequestException("A finished stay can't end after today.");
        }

        foreach (['check_in' => 'checked_in_at', 'check_out' => 'checked_out_at'] as $dateField => $atField) {
            $date = $reservation->get($dateField);
            $at = $reservation->get($atField);
            if (!$reservation->isDirty($dateField) || !$date instanceof Date || $at === null) {
                continue;
            }
            $original = $reservation->getOriginal($dateField);
            if ($original instanceof Date && $original->equals($date)) {
                continue;
            }
            $reservation->set($atField, BusinessTime::onDate($at, $date->format('Y-m-d')));
        }
    }

    /**
     * DELETE /api/reservations/{id} — admin only. Removes a reservation
     * entered by mistake, as long as nothing has been transacted against it
     * yet (see transactionOn()); after that it's part of the property's
     * books, and cancelling is the way to undo it.
     */
    public function delete(int $id): void
    {
        $this->request->allowMethod('delete');

        if (!$this->userHasRole('admin')) {
            throw new ForbiddenException('Only a Manager can delete a reservation.');
        }

        $reservations = $this->fetchTable('Reservations');
        $reservation = $this->scopeToProperty($reservations->find()->where(['Reservations.id' => $id]))
            ->firstOrFail();

        $blocker = $this->transactionOn($reservation);
        if ($blocker !== null) {
            throw new BadRequestException(
                "This reservation can't be deleted: {$blocker}."
                . ($reservation->status === 'cancelled' || $this->isSettled($reservation) ? '' : ' Cancel it instead.'),
            );
        }

        $reservations->getConnection()->transactional(function () use ($reservations, $reservation): void {
            $this->fetchTable('ReservationDiscounts')->deleteAll(['reservation_id' => $reservation->id]);
            $this->fetchTable('ReservationExtraCharges')->deleteAll(['reservation_id' => $reservation->id]);
            $reservations->deleteOrFail($reservation);

            // A guest recorded as in the room no longer is — unless another
            // stay still has them there.
            if (
                $reservation->status === 'checked_in'
                && $reservation->room_id
                && !$reservations->exists([
                    'room_id' => $reservation->room_id,
                    'status' => 'checked_in',
                ])
            ) {
                $rooms = $this->fetchTable('Rooms');
                $room = $rooms->get($reservation->room_id);
                $room->set('status', 'available');
                $rooms->saveOrFail($room);
            }
        });

        $this->set('message', 'Reservation deleted.');
        $this->viewBuilder()->setOption('serialize', ['message']);
    }

    /**
     * What, if anything, has already been transacted against a reservation —
     * money collected or posted for it, or the guest ordering food during the
     * stay. Food orders are tied to the guest rather than the booking, so a
     * non-cancelled order by the same guest dated within the stay counts.
     *
     * @return string|null A reason for the refusal, or null when there's none.
     */
    private function transactionOn(Reservation $reservation): ?string
    {
        $id = (int)$reservation->id;

        if ($this->isSettled($reservation)) {
            return 'its invoice is already settled';
        }
        if ((float)$reservation->downpayment > 0) {
            return 'a downpayment was collected for it';
        }

        $invoices = $this->fetchTable('Invoices');
        $posted = $invoices->exists(['reservation_id' => $id])
            || $invoices->InvoiceLines->exists([
                'source_id' => $id,
                'source_type IN' => [
                    'reservation',
                    'downpayment',
                    'downpayment_credit',
                    'downpayment_refund',
                    'early_check_in',
                ],
            ]);
        if ($posted) {
            return "charges for it are on the guest's invoice";
        }

        if ($reservation->guest_id && $reservation->check_in && $reservation->check_out) {
            $ordered = $this->fetchTable('FoodOrders')->exists([
                'property_id' => $reservation->property_id,
                'guest_id' => $reservation->guest_id,
                'status !=' => 'cancelled',
                'created >=' => BusinessTime::startOf($reservation->check_in->format('Y-m-d')),
                'created <' => BusinessTime::endOf($reservation->check_out->format('Y-m-d')),
            ]);
            if ($ordered) {
                return 'the guest has food orders during this stay';
            }
        }

        return null;
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
        // "Mark unpaid" only flipped the flag and left the posted charge on the
        // invoice. It's gone; the audited Reverse room charge (reason required)
        // arrives with the invoice event records (build step 6).
        if ($status === 'unpaid') {
            throw new BadRequestException(
                "Reversing a room charge isn't available yet. Cancel the reservation to reverse its charges.",
            );
        }

        $this->postChargeOrFail($reservation);
        $this->respondWithReservation($reservation, 200);
    }

    /**
     * POST /api/reservations/{id}/post-room-charge  (Manager + Front Desk Staff)
     *
     * Bills the stay: posts its itemized room charge (and any downpayment
     * credit) to the guest's open invoice. The reservation then reads as
     * Billed, and as Settled once that invoice is settled — billing state comes
     * from the invoice, not from `payment_status`. Posting twice posts once.
     */
    public function postCharge(int $id): void
    {
        $this->request->allowMethod('post');

        $reservation = $this->scopeToProperty(
            $this->fetchTable('Reservations')->find()->where(['Reservations.id' => $id]),
        )->firstOrFail();

        $this->postChargeOrFail($reservation);
        $this->respondWithReservation($reservation, 200);
    }

    /**
     * Post the room charge, or say why it can't be. Unlike the old "Mark
     * paid", which marked a guestless or unpriced stay paid and posted
     * nothing, this refuses: a stay is only billed once a charge line exists.
     */
    private function postChargeOrFail(Reservation $reservation): void
    {
        if ($reservation->status === 'cancelled') {
            throw new BadRequestException("A cancelled reservation can't be billed.");
        }
        if (!$reservation->guest_id) {
            throw new BadRequestException('Add a guest first to post the room charge.');
        }

        $reservations = $this->fetchTable('Reservations');
        $invoices = $this->fetchTable('Invoices');
        $reservations->getConnection()->transactional(function () use ($reservations, $invoices, $reservation): void {
            // Still set for any older screen that reads it; billing no longer does.
            $reservation->set('payment_status', 'paid');
            $reservations->saveOrFail($reservation);

            $invoices->openInvoiceFor(
                (int)$reservation->property_id,
                (int)$reservation->guest_id,
                (int)$reservation->id,
            );
            $this->postRoomCharge($reservation);

            if ($invoices->invoiceForLine('reservation', (int)$reservation->id) === null) {
                throw new BadRequestException(
                    "There's no room rate for this stay, so there's nothing to post. Set the room's rate first.",
                );
            }
        });
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

                // The channel's promotion, as its own line so the folio shows
                // the guest was billed the price the OTA sold them.
                if ($quote['channel_discount'] > 0) {
                    $invoices->addLine(
                        $invoice,
                        sprintf(
                            '%s discount%s',
                            $this->fetchTable('BookingSources')->labelFor(
                                (int)$reservation->property_id,
                                $reservation->source,
                            ),
                            $reservation->channel_discount_type === 'percent'
                                ? sprintf(' (%s%%)', (string)(float)$reservation->channel_discount_value)
                                : '',
                        ),
                        -(float)$quote['channel_discount'],
                        'reservation',
                        (int)$reservation->id,
                    );
                }

                // One line per beneficiary, not one net figure: the folio has
                // to show who the discount was granted to and against which
                // ID, and a room can hold several qualified guests. This is
                // also the only place the peso amount is ever fixed — the
                // beneficiary rows themselves carry no amount, since a
                // booking's price is quoted live until it's charged.
                $totalGuests = max(1, (int)($reservation->total_guests ?? 1));
                foreach ($this->fetchTable('Reservations')->beneficiariesFor($reservation) as $i => $beneficiary) {
                    $share = (float)($quote['statutory_shares'][$i] ?? 0);
                    if ($share <= 0) {
                        continue;
                    }
                    $invoices->addLine(
                        $invoice,
                        sprintf(
                            '%s discount (20%%, 1 of %d guest%s) — %s, ID %s',
                            StatutoryDiscount::label($beneficiary->discount_type),
                            $totalGuests,
                            $totalGuests === 1 ? '' : 's',
                            $beneficiary->beneficiary_name,
                            $beneficiary->id_number,
                        ),
                        -$share,
                        'reservation',
                        (int)$reservation->id,
                    );
                }
                if ($quote['referral_discount'] > 0) {
                    $invoices->addLine(
                        $invoice,
                        'Referral discount',
                        -(float)$quote['referral_discount'],
                        'reservation',
                        (int)$reservation->id,
                    );
                }
                // Extra charges ride with the room charge, one line each, and
                // under the same `reservation` source so the idempotency check
                // above, cancel's reversal and delete's guard all cover them.
                foreach ($this->fetchTable('Reservations')->extrasFor($reservation) as $extra) {
                    $invoices->addLine(
                        $invoice,
                        sprintf('%s × %d', $extra->name, (int)$extra->quantity),
                        round((float)$extra->amount * (int)$extra->quantity, 2),
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
     * How many people the room is billed between, from the request — the
     * divisor the statutory discount is shared over. At least one; falls back
     * to `$current` (the booking's own count on an edit, 1 on a new booking)
     * when the field isn't sent at all.
     */
    private function resolveTotalGuests(int $current = 1): int
    {
        $raw = $this->request->getData('total_guests');
        if ($raw === null || trim((string)$raw) === '') {
            return max(1, $current);
        }

        return max(1, (int)$raw);
    }

    /**
     * The Senior/PWD beneficiaries from the request, validated.
     *
     * Each needs a name and an ID number: the point of recording a
     * beneficiary at all is being able to account for the discount afterwards,
     * and "senior, no name" accounts for nothing. There can't be more of them
     * than guests on the booking either — that would discount more of the room
     * than there are people to discount for. Mirrors FoodOrdersTable::place()'s
     * own parsing of the same shape.
     *
     * @return list<array{discount_type: string, name: string, id_number: string}>
     */
    private function parseBeneficiaries(int $totalGuests): array
    {
        $beneficiaries = [];
        foreach ((array)($this->request->getData('discount_beneficiaries') ?? []) as $raw) {
            $type = $raw['discount_type'] ?? null;
            if (!in_array($type, StatutoryDiscount::TYPES, true)) {
                throw new BadRequestException('Unknown discount type.');
            }
            $name = trim((string)($raw['name'] ?? ''));
            $idNumber = trim((string)($raw['id_number'] ?? ''));
            if ($name === '' || $idNumber === '') {
                throw new BadRequestException(
                    'Each Senior/PWD discount needs the beneficiary name and ID number.',
                );
            }
            $beneficiaries[] = ['discount_type' => $type, 'name' => $name, 'id_number' => $idNumber];
        }

        if (count($beneficiaries) > $totalGuests) {
            throw new BadRequestException(
                'The number of Senior/PWD beneficiaries cannot exceed the total guests.',
            );
        }

        return $beneficiaries;
    }

    /**
     * Replace a booking's beneficiaries with `$beneficiaries`.
     *
     * Wholesale, like FoodMenuItemsController::saveIngredients(): an edit that
     * dropped one guest and added another is easier to get right by rewriting
     * the set than by diffing it, and nothing else references these rows.
     *
     * The saved rows are set back onto the entity (clean, so they can't ride
     * along into a later save) because quote() prices from them, and the
     * downpayment is quoted moments later in the same transaction.
     *
     * @param list<array{discount_type: string, name: string, id_number: string}> $beneficiaries
     */
    private function saveBeneficiaries(Reservation $reservation, array $beneficiaries): void
    {
        $discounts = $this->fetchTable('ReservationDiscounts');
        $discounts->deleteAll(['reservation_id' => $reservation->id]);

        $saved = [];
        foreach ($beneficiaries as $beneficiary) {
            $saved[] = $discounts->saveOrFail($discounts->newEntity([
                'reservation_id' => $reservation->id,
                'discount_type' => $beneficiary['discount_type'],
                'beneficiary_name' => $beneficiary['name'],
                'id_number' => $beneficiary['id_number'],
            ]));
        }

        $reservation->set('reservation_discounts', $saved);
        $reservation->setDirty('reservation_discounts', false);
    }

    /**
     * The extra charges picked on the booking, from `extra_charges[]`
     * (`{extra_charge_id, quantity}`), resolved to the rows saveExtras()
     * writes — or null when the key wasn't sent (an edit then leaves them).
     *
     * Only the admin's custom charges can be picked: the built-in early
     * check-in fee is billed by check-in itself. A charge already on the
     * booking keeps the name and amount it was picked at, even if the admin
     * has since repriced, deactivated or deleted it; a newly picked one must
     * be active and is snapshotted as it is now. The same charge sent twice
     * is one line with the quantities added.
     *
     * @return list<array{extra_charge_id: int, name: string, amount: string, quantity: int}>|null
     */
    private function parseExtras(int $propertyId, ?Reservation $current = null): ?array
    {
        $raw = $this->request->getData('extra_charges');
        if ($raw === null) {
            return null;
        }
        if (!is_array($raw)) {
            throw new BadRequestException('extra_charges must be a list.');
        }

        $max = ReservationExtraChargesTable::MAX_QUANTITY;
        $quantities = [];
        foreach ($raw as $row) {
            $id = (int)($row['extra_charge_id'] ?? 0);
            if ($id <= 0) {
                throw new BadRequestException('Each extra charge needs an extra_charge_id.');
            }
            $quantities[$id] = ($quantities[$id] ?? 0) + (int)($row['quantity'] ?? 1);
        }
        if ($quantities === []) {
            return [];
        }

        $charges = $this->fetchTable('ExtraCharges')->find()
            ->where([
                'ExtraCharges.property_id' => $propertyId,
                'ExtraCharges.id IN' => array_keys($quantities),
                'ExtraCharges.code IS' => null,
            ])
            ->all()
            ->indexBy('id')
            ->toArray();
        $onBooking = [];
        if ($current !== null) {
            foreach ($this->fetchTable('Reservations')->extrasFor($current) as $extra) {
                $onBooking[(int)$extra->extra_charge_id] = $extra;
            }
        }

        $rows = [];
        foreach ($quantities as $id => $quantity) {
            if ($quantity < 1 || $quantity > $max) {
                throw new BadRequestException("An extra charge's quantity must be between 1 and {$max}.");
            }
            $source = $onBooking[$id] ?? (($charges[$id] ?? null)?->is_active ? $charges[$id] : null);
            if ($source === null) {
                throw new BadRequestException('One of the extra charges picked is no longer available.');
            }
            $rows[] = [
                'extra_charge_id' => $id,
                'name' => (string)$source->name,
                'amount' => (string)$source->amount,
                'quantity' => $quantity,
            ];
        }

        return $rows;
    }

    /**
     * Replace a booking's extra charges with `$extras`, wholesale and set
     * back onto the entity clean — as saveBeneficiaries() does, and for the
     * same reason (quote() prices from them moments later).
     *
     * @param list<array{extra_charge_id: int, name: string, amount: string, quantity: int}> $extras
     */
    private function saveExtras(Reservation $reservation, array $extras): void
    {
        $table = $this->fetchTable('ReservationExtraCharges');
        $table->deleteAll(['reservation_id' => $reservation->id]);

        $saved = [];
        foreach ($extras as $extra) {
            $saved[] = $table->saveOrFail($table->newEntity(['reservation_id' => $reservation->id] + $extra));
        }

        $reservation->set('reservation_extra_charges', $saved);
        $reservation->setDirty('reservation_extra_charges', false);
    }

    /**
     * The channel discount from the request, as `{type, value}` (both null for
     * none).
     *
     * It's the booking channel's own promotion, so a walk-in never carries
     * one however the form was filled in before the type was switched. On an
     * edit (`$current`), omitting `channel_discount_type` altogether keeps the
     * booking's existing discount; sending it blank clears it. A type with no
     * value is refused rather than guessed — the range checks (> 0, a
     * percentage ≤ 100) are the table validator's.
     *
     * @return array{type: string|null, value: string|null}
     */
    private function resolveChannelDiscount(string $source, ?Reservation $current = null): array
    {
        $none = ['type' => null, 'value' => null];
        if ($source === BookingSourcesTable::WALK_IN) {
            return $none;
        }
        if ($current !== null && $this->request->getData('channel_discount_type') === null) {
            return [
                'type' => $current->channel_discount_type,
                'value' => $current->channel_discount_value,
            ];
        }

        $type = $this->trimmedOrNull('channel_discount_type');
        if ($type === null) {
            return $none;
        }
        if (!in_array($type, ReservationsTable::CHANNEL_DISCOUNT_TYPES, true)) {
            throw new BadRequestException('Channel discount must be a percentage or a fixed amount.');
        }
        $value = $this->trimmedOrNull('channel_discount_value');
        if ($value === null) {
            throw new BadRequestException('Enter how much the channel discounted the booking.');
        }

        return ['type' => $type, 'value' => $value];
    }

    /**
     * The referral discount amount from the request, or null when not set —
     * distinct from the statutory Senior/PWD discount, since referral stacks
     * with it rather than being one more kind of beneficiary.
     */
    private function resolveReferralAmount(): ?string
    {
        return $this->trimmedOrNull('discount_amount');
    }

    /**
     * A request field as a trimmed string, or null when it was absent or
     * blank — so an empty form input lands as NULL rather than "".
     */
    private function trimmedOrNull(string $field): ?string
    {
        $raw = $this->request->getData($field);
        if ($raw === null) {
            return null;
        }
        $value = trim((string)$raw);

        return $value === '' ? null : $value;
    }

    /**
     * Use an existing guest_id, or create a guest inline from guest_name.
     */
    private function resolveGuestId(int $propertyId): ?int
    {
        $guestId = $this->request->getData('guest_id');
        if ($guestId) {
            // Refuse an id that isn't this property's. Previously completeGuest()
            // simply found nothing and returned, and the id was written onto the
            // booking regardless — which meant either a guest from another
            // property (whose details the reservations index would then hand
            // back) or, with stale client state, a dangling reference and a
            // blank name in the table with no error to explain it.
            if (!$this->completeGuest((int)$guestId, $propertyId)) {
                throw new BadRequestException('That guest is not registered at this property.');
            }

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
     *
     * @return bool False when no such guest exists *at this property* — the
     *   caller must not use the id in that case.
     */
    private function completeGuest(int $guestId, int $propertyId): bool
    {
        $guests = $this->fetchTable('Guests');
        $guest = $guests->find()
            ->where(['Guests.id' => $guestId, 'Guests.property_id' => $propertyId])
            ->first();
        if ($guest === null) {
            return false;
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

        return true;
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

    /**
     * Whether a new booking records a stay that started before today — which
     * only an admin may add (history the desk didn't record at the time). A
     * receptionist's walk-in isn't one: its date is forced to today whatever
     * the form sent, so a stale date there is ignored, not refused.
     *
     * @throws \Cake\Http\Exception\ForbiddenException When a non-admin sends one.
     */
    private function isBackdated(string $source, mixed $checkIn): bool
    {
        if (!$this->isBeforeToday($checkIn)) {
            return false;
        }

        $isAdmin = $this->userHasRole('admin');
        if ($source === BookingSourcesTable::WALK_IN && !$isAdmin) {
            return false;
        }
        if (!$isAdmin) {
            throw new ForbiddenException('Only a Manager can add a booking with a check-in before today.');
        }

        return true;
    }

    /**
     * A well-formed `Y-m-d` date earlier than today. Anything else is left for
     * the table's own validation to reject.
     */
    private function isBeforeToday(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
            && $value < BusinessTime::today()->format('Y-m-d');
    }

    /**
     * Advance booking (check-in after today): collect a 50% downpayment of
     * the quoted total (promo rate and discount included) as an
     * immediately-settled invoice, so it shows in collections right away.
     * A no-op if check-in isn't in the future, or the quoted downpayment is
     * 0 — used by both add() (a brand-new booking) and edit() (a 'booked'
     * reservation edited into becoming, or remaining, an advance booking;
     * edit() only ever reaches here when no downpayment was collected yet).
     */
    private function collectAdvanceDownpayment(Reservation $reservation, int $propertyId, int $guestId): void
    {
        if ($reservation->check_in === null || $reservation->check_in <= BusinessTime::today()) {
            return;
        }

        $reservations = $this->fetchTable('Reservations');
        $quote = $reservations->quote(
            $reservation,
            $this->resolveBaseRate($propertyId, $reservation->room_id ? (int)$reservation->room_id : null),
        );
        $downpayment = round($quote['total'] * self::DOWNPAYMENT_RATE, 2);
        if ($downpayment <= 0) {
            return;
        }

        $reservation->set('downpayment', $downpayment);
        $reservations->saveOrFail($reservation);
        $this->fetchTable('Invoices')->settledInvoiceWith(
            $propertyId,
            $guestId,
            (int)$reservation->id,
            sprintf('Downpayment (50%%) — booking #%d', $reservation->id),
            $downpayment,
            'downpayment',
            (int)$reservation->id,
        );
    }

    private function respondWithReservation(Reservation $reservation, int $status): void
    {
        $reservations = $this->fetchTable('Reservations');
        $full = $reservations->get(
            $reservation->id,
            contain: ['Rooms', 'Guests', 'Receptionist', 'ReservationDiscounts', 'ReservationExtraCharges'],
        );
        $full->set('quote', $reservations->quote($full, $this->resolveBaseRate((int)$full->property_id, $full->room_id)));
        $chargeInvoice = $this->roomChargeStatuses([(int)$full->id])[(int)$full->id] ?? null;
        $full->set('room_charge_invoice', $chargeInvoice);
        $full->set('billing_state', self::billingState($chargeInvoice));

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
