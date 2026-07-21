<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Model\Table\BookingSourcesTable;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\ForbiddenException;

/**
 * BookingSources — the admin-configurable list of OTA sources a property
 * books through. Everyone reads them (the New Reservation form's Source
 * dropdown needs the list), but only owners/admins add, rename, or remove
 * one — like room rates and promo rates. 'walk_in' is not managed here; it's
 * a fixed option the frontend always shows alongside these.
 */
class BookingSourcesController extends AppController
{
    /**
     * GET /api/booking-sources
     */
    public function index(): void
    {
        $sources = $this->fetchTable('BookingSources');

        $propertyId = $this->effectivePropertyId();
        if ($propertyId !== null) {
            $sources->seedDefaultsFor($propertyId);
        }

        $query = $this->scopeToProperty($sources->find()->orderBy(['BookingSources.name' => 'ASC']));

        $this->set('bookingSources', $query->all());
        $this->viewBuilder()->setOption('serialize', ['bookingSources']);
    }

    /**
     * POST /api/booking-sources — add a custom source (code is derived from
     * the name and fixed from then on).
     */
    public function add(): void
    {
        $this->request->allowMethod('post');
        $this->assertManager();

        $propertyId = $this->effectivePropertyId();
        if ($propertyId === null) {
            throw new BadRequestException('property_id is required.');
        }

        $sources = $this->fetchTable('BookingSources');
        $name = trim((string)$this->request->getData('name'));
        $this->assertNameNotTaken($sources, $propertyId, $name);

        $source = $sources->newEntity([
            'property_id' => $propertyId,
            'name' => $name,
            'code' => $sources->slugFor($name, $propertyId),
        ]);

        if (!$sources->save($source)) {
            $this->validationFailed($source->getErrors());

            return;
        }

        $this->response = $this->response->withStatus(201);
        $this->set('bookingSource', $source);
        $this->viewBuilder()->setOption('serialize', ['bookingSource']);
    }

    /**
     * PATCH/PUT /api/booking-sources/{id} — rename only; `code` never changes
     * once set, since it's what's stored on existing reservations/promo rates.
     */
    public function edit(int $id): void
    {
        $this->request->allowMethod(['patch', 'put', 'post']);
        $this->assertManager();

        $sources = $this->fetchTable('BookingSources');
        $source = $this->scopeToProperty($sources->find()->where(['BookingSources.id' => $id]))->firstOrFail();

        $name = trim((string)$this->request->getData('name'));
        $this->assertNameNotTaken($sources, (int)$source->property_id, $name, $id);

        $sources->patchEntity(
            $source,
            ['name' => $name],
            ['accessibleFields' => ['property_id' => false, 'code' => false]],
        );

        if (!$sources->save($source)) {
            $this->validationFailed($source->getErrors());

            return;
        }

        $this->set('bookingSource', $source);
        $this->viewBuilder()->setOption('serialize', ['bookingSource']);
    }

    /**
     * DELETE /api/booking-sources/{id} — refused if any reservation or promo
     * rate still references it; remove those first (or leave the source in
     * place — deleting isn't required just to stop using it going forward).
     */
    public function delete(int $id): void
    {
        $this->request->allowMethod(['delete', 'post']);
        $this->assertManager();

        $sources = $this->fetchTable('BookingSources');
        $source = $this->scopeToProperty($sources->find()->where(['BookingSources.id' => $id]))->firstOrFail();

        $inUseByReservations = $this->fetchTable('Reservations')->exists([
            'Reservations.property_id' => $source->property_id,
            'Reservations.source' => $source->code,
        ]);
        $inUseByPromoRates = $this->fetchTable('PromoRates')->exists([
            'PromoRates.property_id' => $source->property_id,
            'PromoRates.source' => $source->code,
        ]);
        if ($inUseByReservations || $inUseByPromoRates) {
            throw new BadRequestException('This source is still used by reservations or promo rates.');
        }

        $sources->deleteOrFail($source);

        $this->set('deleted', true);
        $this->viewBuilder()->setOption('serialize', ['deleted']);
    }

    private function assertNameNotTaken(
        BookingSourcesTable $sources,
        int $propertyId,
        string $name,
        ?int $excludeId = null,
    ): void {
        if ($name === '') {
            throw new BadRequestException('Name is required.');
        }
        if (mb_strtolower($name) === 'walk-in' || mb_strtolower($name) === 'walk in') {
            throw new BadRequestException('"Walk-in" is a built-in option and isn\'t a bookable source.');
        }

        $query = $sources->find()
            ->select(['name'])
            ->where(['BookingSources.property_id' => $propertyId]);
        if ($excludeId !== null) {
            $query->where(['BookingSources.id !=' => $excludeId]);
        }

        foreach ($query->all()->extract('name') as $existingName) {
            if (mb_strtolower(trim((string)$existingName)) === mb_strtolower($name)) {
                throw new BadRequestException(sprintf('A source named "%s" already exists.', $name));
            }
        }
    }

    private function assertManager(): void
    {
        if (!$this->userHasRole('owner', 'admin')) {
            throw new ForbiddenException('Only owners and admins may manage booking sources.');
        }
    }

    private function validationFailed(array $errors): void
    {
        $this->response = $this->response->withStatus(422);
        $this->set('errors', $errors);
        $this->viewBuilder()->setOption('serialize', ['errors']);
    }
}
