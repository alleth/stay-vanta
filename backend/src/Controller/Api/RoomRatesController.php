<?php
declare(strict_types=1);

namespace App\Controller\Api;

use Cake\Http\Exception\BadRequestException;

/**
 * Room rates. A rate may target a specific room (room_id) or apply
 * property-wide (room_id null).
 */
class RoomRatesController extends AppController
{
    /**
     * GET /api/room-rates[?room_id=NN]
     */
    public function index(): void
    {
        $rates = $this->fetchTable('RoomRates');
        $query = $this->scopeToProperty(
            $rates->find()->contain(['Rooms'])->orderBy(['RoomRates.name' => 'ASC'])
        );

        $roomId = $this->request->getQuery('room_id');
        if ($roomId !== null) {
            $query->where(['RoomRates.room_id IS' => (int)$roomId]);
        }

        $this->set('roomRates', $query->all());
        $this->viewBuilder()->setOption('serialize', ['roomRates']);
    }

    /**
     * POST /api/room-rates
     */
    public function add(): void
    {
        $this->request->allowMethod('post');

        $propertyId = $this->effectivePropertyId();
        if ($propertyId === null) {
            throw new BadRequestException('property_id is required.');
        }

        $rates = $this->fetchTable('RoomRates');
        $rate = $rates->newEntity([
            'property_id' => $propertyId,
            'room_id' => $this->request->getData('room_id'),
            'name' => $this->request->getData('name'),
            'base_rate' => $this->request->getData('base_rate'),
        ]);

        if (!$rates->save($rate)) {
            $this->response = $this->response->withStatus(422);
            $this->set('errors', $rate->getErrors());
            $this->viewBuilder()->setOption('serialize', ['errors']);

            return;
        }

        $this->response = $this->response->withStatus(201);
        $this->set('roomRate', $rate);
        $this->viewBuilder()->setOption('serialize', ['roomRate']);
    }
}
