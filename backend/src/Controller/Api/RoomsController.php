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
     * PATCH /api/rooms/{id} — update number/type/status.
     */
    public function edit(int $id): void
    {
        $this->request->allowMethod(['patch', 'put', 'post']);
        $rooms = $this->fetchTable('Rooms');
        $room = $this->scopeToProperty($rooms->find()->where(['Rooms.id' => $id]))->firstOrFail();

        $rooms->patchEntity($room, [
            'room_number' => $this->request->getData('room_number'),
            'room_type' => $this->request->getData('room_type'),
            'status' => $this->request->getData('status'),
        ], ['accessibleFields' => ['property_id' => false]]);

        if (!$rooms->save($room)) {
            $this->validationFailed($room->getErrors());

            return;
        }

        $this->set('room', $room);
        $this->viewBuilder()->setOption('serialize', ['room']);
    }

    private function validationFailed(array $errors): void
    {
        $this->response = $this->response->withStatus(422);
        $this->set('errors', $errors);
        $this->viewBuilder()->setOption('serialize', ['errors']);
    }
}
