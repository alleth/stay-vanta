<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Auth\Permissions;
use App\Model\Table\RoomEventsTable;
use App\Model\Table\RoomsTable;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\ForbiddenException;

/**
 * Rooms.
 */
class RoomsController extends AppController
{
    /**
     * GET /api/rooms[?status=available]
     */
    public function index(): void
    {
        $this->authorize(Permissions::ROOMS_ROOM_VIEW);
        $rooms = $this->fetchTable('Rooms');
        $query = $this->scopeToProperty(
            $rooms->find()->contain(['RoomRates'])->orderBy(['Rooms.room_number' => 'ASC'])
        );

        $status = $this->request->getQuery('status');
        if ($status !== null) {
            $query->where(['Rooms.status' => $status]);
        }

        $this->set('rooms', $query->all());
        $this->viewBuilder()->setOption('serialize', ['rooms']);
    }

    /**
     * POST /api/rooms
     */
    public function add(): void
    {
        $this->request->allowMethod('post');

        // Only owners/admins may add rooms; receptionists manage existing ones.
        $this->authorize(Permissions::SETTINGS_ROOM_MANAGE, 'Only Managers may add rooms.');

        $propertyId = $this->effectivePropertyId();
        if ($propertyId === null) {
            throw new BadRequestException('property_id is required.');
        }

        $rooms = $this->fetchTable('Rooms');
        $room = $rooms->newEntity([
            'property_id' => $propertyId,
            'room_number' => $this->request->getData('room_number'),
            'room_type' => $this->request->getData('room_type'),
            // A new room is vacant and in service; service changes go through
            // service() (G4), occupancy through check-in and check-out.
            'status' => 'available',
            'service_status' => RoomsTable::IN_SERVICE,
        ]);

        if (!$rooms->save($room, $this->auditOptions())) {
            $this->validationFailed($room->getErrors());

            return;
        }

        $this->response = $this->response->withStatus(201);
        $this->set('room', $room);
        $this->viewBuilder()->setOption('serialize', ['room']);
    }

    /**
     * PATCH /api/rooms/{id} — update number/type/status. Any authenticated
     * staff may change `status` (a receptionist's day-to-day operational
     * need); actually changing `room_number`/`room_type` — fixing a typo made
     * when the room was added — is owner/admin only.
     */
    public function edit(int $id): void
    {
        $this->request->allowMethod(['patch', 'put', 'post']);
        $this->authorize(Permissions::ROOMS_ROOM_UPDATE_STATUS);
        $rooms = $this->fetchTable('Rooms');
        $room = $this->scopeToProperty($rooms->find()->where(['Rooms.id' => $id]))->firstOrFail();

        $newNumber = $this->request->getData('room_number');
        $newType = $this->request->getData('room_type');
        $changingDetails = ($newNumber !== null && $newNumber !== $room->room_number)
            || ($newType !== null && $newType !== $room->room_type);
        if ($changingDetails && !$this->can(Permissions::SETTINGS_ROOM_MANAGE)) {
            throw new ForbiddenException('Only Managers may rename or retype a room.');
        }

        // Occupancy follows check-in and check-out only (G4, R4); maintenance
        // and out of service go through service(), recorded with a reason.
        $status = $this->request->getData('status');
        if ($status !== null && $status !== $room->status) {
            throw new BadRequestException(
                'A room\'s occupancy follows check-in and check-out. To take a room off sale, use Maintenance '
                . 'or Out of service.',
            );
        }

        $rooms->patchEntity($room, [
            'room_number' => $newNumber,
            'room_type' => $newType,
        ], ['accessibleFields' => ['property_id' => false]]);

        if (!$rooms->save($room, $this->auditOptions())) {
            $this->validationFailed($room->getErrors());

            return;
        }

        $this->set('room', $room);
        $this->viewBuilder()->setOption('serialize', ['room']);
    }

    /**
     * DELETE /api/rooms/{id} — owner/admin only, for removing a room created
     * with wrong details. Refused if the room has reservation history (that data
     * must be preserved). Soft-deleted (step 9): the room and its rates stay
     * as history, hidden from lists.
     */
    public function delete(int $id): void
    {
        $this->request->allowMethod(['delete', 'post']);

        $this->authorize(Permissions::SETTINGS_ROOM_MANAGE, 'Only Managers may delete rooms.');

        $rooms = $this->fetchTable('Rooms');
        $room = $this->scopeToProperty($rooms->find()->where(['Rooms.id' => $id]))->firstOrFail();

        $reservations = $this->fetchTable('Reservations');
        $hasHistory = $reservations->find()->where(['Reservations.room_id' => $id])->count() > 0;
        if ($hasHistory) {
            throw new BadRequestException('Cannot delete a room that has reservations. Set it to maintenance instead.');
        }

        $rooms->getConnection()->transactional(function () use ($rooms, $room): void {
            // Soft delete with a reason (step 9): the room and its rates stay as history.
            $this->softDelete($rooms, $room);
        });

        $this->set('ok', true);
        $this->viewBuilder()->setOption('serialize', ['ok']);
    }

    /**
     * POST /api/rooms/{id}/service { service_status, reason } → { room }
     *
     * Change whether a room can be sold and assigned (final review G4):
     * in service, maintenance (off sale briefly; Front Desk and Managers) or
     * out of service (off sale until further notice; Managers only, as is
     * bringing a room back from it). Lock, then check, then change, then
     * record: one `room_events` row with the status before and after, who,
     * why and the request. Starting maintenance and taking a room out of
     * service need a reason; returning it, optionally. A change to the
     * status it already has is refused and records nothing. Occupancy is
     * untouched: a guest in the room stays in it.
     */
    public function service(int $id): void
    {
        $this->request->allowMethod('post');
        $this->authorize(Permissions::ROOMS_ROOM_UPDATE_STATUS);
        $target = (string)$this->request->getData('service_status');
        if (!in_array($target, RoomsTable::SERVICE_STATUSES, true)) {
            throw new BadRequestException('Choose in service, maintenance or out of service.');
        }
        $rooms = $this->fetchTable('Rooms');
        $this->scopeToProperty($rooms->find()->where(['Rooms.id' => $id]))->firstOrFail();

        $room = $rooms->getConnection()->transactional(function () use ($rooms, $id, $target) {
            $room = $rooms->find()->where(['Rooms.id' => $id])->epilog('FOR UPDATE')->firstOrFail();
            $before = (string)($room->service_status ?? RoomsTable::IN_SERVICE);
            if ($before === $target) {
                throw new BadRequestException(sprintf(
                    'Room %s is already %s.',
                    $room->room_number,
                    RoomsTable::SERVICE_LABELS[$target],
                ));
            }
            if (in_array(RoomsTable::OUT_OF_SERVICE, [$before, $target], true)) {
                $this->authorize(
                    Permissions::ROOMS_ROOM_REMOVE_FROM_SERVICE,
                    'Only Managers can take a room out of service or return it.',
                );
            }
            $room->set('service_status', $target);
            // The combined status older readers use: a guest in the room stays.
            RoomsTable::setOccupied($room, $room->status === 'occupied');
            $rooms->saveOrFail($room, ['atomic' => false]);
            /** @var \App\Model\Table\RoomEventsTable $events */
            $events = $this->fetchTable('RoomEvents');
            $events->record($this->eventContext(), RoomEventsTable::typeOf($before, $target), $room, [
                'columns' => ['service_before' => $before, 'service_after' => $target],
                'changes' => ['service_status' => ['before' => $before, 'after' => $target]],
            ]);

            return $room;
        });

        $this->set('room', $room);
        $this->viewBuilder()->setOption('serialize', ['room']);
    }

    /**
     * GET /api/rooms/{id}/history[?page=] → { events, page, has_more }
     *
     * A room's service history, newest first (G4, Managers only, R1): the
     * status before and after, who changed it, why, when and the request id.
     * An imported line has no actor, reason or start time: none were recorded.
     */
    public function history(int $id): void
    {
        $this->request->allowMethod('get');
        $this->authorize(Permissions::ROOMS_ROOM_VIEW_HISTORY, 'Only Managers can see who changed a room.');
        $room = $this->scopeToProperty($this->fetchTable('Rooms')->find()->where(['Rooms.id' => $id]))
            ->firstOrFail();

        $perPage = 25;
        $page = min(200, max(1, (int)($this->request->getQuery('page') ?? 1)));
        $rows = $this->fetchTable('RoomEvents')->find()
            ->where(['room_id' => $room->id])
            ->orderBy(['occurred_at' => 'DESC', 'id' => 'DESC'])
            ->limit($perPage + 1)->offset(($page - 1) * $perPage)
            ->all()->toList();
        $hasMore = count($rows) > $perPage;
        $rows = array_slice($rows, 0, $perPage);
        $actorIds = array_values(array_unique(array_filter(array_map(fn($r) => $r->actor_id, $rows))));
        $names = $actorIds === [] ? [] : $this->fetchTable('Users')->find()
            ->select(['id', 'name'])->where(['id IN' => $actorIds])->all()->combine('id', 'name')->toArray();

        $events = array_map(fn($r) => [
            'id' => (int)$r->id,
            'at' => $r->occurred_at,
            'event' => $r->event_type,
            'before' => $r->service_before,
            'after' => $r->service_after,
            'actor' => $r->actor_id !== null ? ($names[$r->actor_id] ?? null) : null,
            'actor_role' => $r->actor_role,
            'recorded' => $r->source !== 'import',
            'reason' => $r->reason,
            'request_id' => $r->correlation_id,
        ], $rows);

        $this->set(['events' => $events, 'page' => $page, 'has_more' => $hasMore]);
        $this->viewBuilder()->setOption('serialize', ['events', 'page', 'has_more']);
    }

    private function validationFailed(array $errors): void
    {
        $this->response = $this->response->withStatus(422);
        $this->set('errors', $errors);
        $this->viewBuilder()->setOption('serialize', ['errors']);
    }
}
