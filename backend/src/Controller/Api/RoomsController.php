<?php
declare(strict_types=1);

namespace App\Controller\Api;

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
        if (!$this->userHasRole('owner', 'admin')) {
            throw new ForbiddenException('Only owners and admins may add rooms.');
        }

        $propertyId = $this->effectivePropertyId();
        if ($propertyId === null) {
            throw new BadRequestException('property_id is required.');
        }

        $rooms = $this->fetchTable('Rooms');
        $room = $rooms->newEntity([
            'property_id' => $propertyId,
            'room_number' => $this->request->getData('room_number'),
            'room_type' => $this->request->getData('room_type'),
            'status' => $this->request->getData('status') ?? 'available',
        ]);

        if (!$rooms->save($room)) {
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
        $rooms = $this->fetchTable('Rooms');
        $room = $this->scopeToProperty($rooms->find()->where(['Rooms.id' => $id]))->firstOrFail();

        $newNumber = $this->request->getData('room_number');
        $newType = $this->request->getData('room_type');
        $changingDetails = ($newNumber !== null && $newNumber !== $room->room_number)
            || ($newType !== null && $newType !== $room->room_type);
        if ($changingDetails && !$this->userHasRole('owner', 'admin')) {
            throw new ForbiddenException('Only owners and admins may rename or retype a room.');
        }

        $rooms->patchEntity($room, [
            'room_number' => $newNumber,
            'room_type' => $newType,
            'status' => $this->request->getData('status'),
        ], ['accessibleFields' => ['property_id' => false]]);

        if (!$rooms->save($room)) {
            $this->validationFailed($room->getErrors());

            return;
        }

        $this->set('room', $room);
        $this->viewBuilder()->setOption('serialize', ['room']);
    }

    /**
     * DELETE /api/rooms/{id} — owner/admin only, for removing a room created
     * with wrong details. Refused if the room has reservation history (that data
     * must be preserved); any room-specific rates are removed with it.
     */
    public function delete(int $id): void
    {
        $this->request->allowMethod(['delete', 'post']);

        if (!$this->userHasRole('owner', 'admin')) {
            throw new ForbiddenException('Only owners and admins may delete rooms.');
        }

        $rooms = $this->fetchTable('Rooms');
        $room = $this->scopeToProperty($rooms->find()->where(['Rooms.id' => $id]))->firstOrFail();

        $reservations = $this->fetchTable('Reservations');
        $hasHistory = $reservations->find()->where(['Reservations.room_id' => $id])->count() > 0;
        if ($hasHistory) {
            throw new BadRequestException('Cannot delete a room that has reservations. Set it to maintenance instead.');
        }

        $rooms->getConnection()->transactional(function () use ($rooms, $room, $id): void {
            $this->fetchTable('RoomRates')->deleteAll(['room_id' => $id]);
            $rooms->deleteOrFail($room);
        });

        $this->set('ok', true);
        $this->viewBuilder()->setOption('serialize', ['ok']);
    }

    private function validationFailed(array $errors): void
    {
        $this->response = $this->response->withStatus(422);
        $this->set('errors', $errors);
        $this->viewBuilder()->setOption('serialize', ['errors']);
    }
}
