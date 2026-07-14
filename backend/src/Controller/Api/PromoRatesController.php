<?php
declare(strict_types=1);

namespace App\Controller\Api;

use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\ForbiddenException;

/**
 * Promo rates — per-OTA rate multipliers the admin configures per booking
 * source (Cocotel, Agoda, Trip.com, TripAdvisor), optionally per room. The
 * promo nightly price is the room's original rate × the multiplier; the New
 * Reservation form auto-fills from these. Receptionists read them but only
 * owners/admins create, edit, or delete them.
 */
class PromoRatesController extends AppController
{
    /**
     * GET /api/promo-rates[?source=agoda]
     */
    public function index(): void
    {
        $promoRates = $this->fetchTable('PromoRates');
        $query = $this->scopeToProperty(
            $promoRates->find()->contain(['Rooms'])
                ->orderBy(['PromoRates.source' => 'ASC', 'PromoRates.room_id' => 'ASC'])
        );

        $source = $this->request->getQuery('source');
        if ($source !== null) {
            $query->where(['PromoRates.source' => $source]);
        }

        $this->set('promoRates', $query->all());
        $this->viewBuilder()->setOption('serialize', ['promoRates']);
    }

    /**
     * POST /api/promo-rates
     */
    public function add(): void
    {
        $this->request->allowMethod('post');

        if (!$this->userHasRole('owner', 'admin')) {
            throw new ForbiddenException('Only owners and admins may add promo rates.');
        }

        $propertyId = $this->effectivePropertyId();
        if ($propertyId === null) {
            throw new BadRequestException('property_id is required.');
        }

        $promoRates = $this->fetchTable('PromoRates');
        $rate = $promoRates->newEntity([
            'property_id' => $propertyId,
            'room_id' => $this->request->getData('room_id') ?: null,
            'source' => $this->request->getData('source'),
            'multiplier' => $this->request->getData('multiplier'),
        ]);

        if (!$promoRates->save($rate)) {
            $this->response = $this->response->withStatus(422);
            $this->set('errors', $rate->getErrors());
            $this->viewBuilder()->setOption('serialize', ['errors']);

            return;
        }

        $this->response = $this->response->withStatus(201);
        $this->set('promoRate', $rate);
        $this->viewBuilder()->setOption('serialize', ['promoRate']);
    }

    /**
     * PATCH/PUT /api/promo-rates/{id} — fix the source, target room, or multiplier.
     */
    public function edit(int $id): void
    {
        $this->request->allowMethod(['patch', 'put', 'post']);

        if (!$this->userHasRole('owner', 'admin')) {
            throw new ForbiddenException('Only owners and admins may edit promo rates.');
        }

        $promoRates = $this->fetchTable('PromoRates');
        $rate = $this->scopeToProperty($promoRates->find()->where(['PromoRates.id' => $id]))->firstOrFail();

        // room_id may be intentionally set to null ("all rooms"); read it raw.
        $promoRates->patchEntity($rate, [
            'source' => $this->request->getData('source'),
            'multiplier' => $this->request->getData('multiplier'),
            'room_id' => $this->request->getData('room_id') ?: null,
        ], ['accessibleFields' => ['property_id' => false]]);

        if (!$promoRates->save($rate)) {
            $this->response = $this->response->withStatus(422);
            $this->set('errors', $rate->getErrors());
            $this->viewBuilder()->setOption('serialize', ['errors']);

            return;
        }

        $this->set('promoRate', $rate);
        $this->viewBuilder()->setOption('serialize', ['promoRate']);
    }

    /**
     * DELETE /api/promo-rates/{id}
     */
    public function delete(int $id): void
    {
        $this->request->allowMethod('delete');

        if (!$this->userHasRole('owner', 'admin')) {
            throw new ForbiddenException('Only owners and admins may delete promo rates.');
        }

        $promoRates = $this->fetchTable('PromoRates');
        $rate = $this->scopeToProperty($promoRates->find()->where(['PromoRates.id' => $id]))->firstOrFail();

        $promoRates->deleteOrFail($rate);

        $this->set('deleted', true);
        $this->viewBuilder()->setOption('serialize', ['deleted']);
    }
}
