<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Model\Entity\ExtraCharge;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\ForbiddenException;

/**
 * ExtraCharges — admin-configurable surcharges (e.g. an early check-in fee).
 *
 * Everyone reads them (a receptionist needs the early check-in amount to warn
 * before charging), but only owners/admins create, edit, or delete — like room
 * rates. The built-in early check-in row is always present and cannot be deleted.
 */
class ExtraChargesController extends AppController
{
    /**
     * GET /api/extra-charges
     */
    public function index(): void
    {
        $charges = $this->fetchTable('ExtraCharges');

        $propertyId = $this->effectivePropertyId();
        if ($propertyId !== null) {
            // Make sure the built-in early check-in row exists for this property.
            $charges->earlyCheckInFor($propertyId);
        }

        // System rows (early check-in) first, then custom ones by name.
        $query = $this->scopeToProperty(
            $charges->find()->orderBy(['ExtraCharges.code' => 'DESC', 'ExtraCharges.name' => 'ASC']),
        );

        $this->set('extraCharges', $query->all());
        $this->viewBuilder()->setOption('serialize', ['extraCharges']);
    }

    /**
     * POST /api/extra-charges — add a custom charge (code is always null).
     */
    public function add(): void
    {
        $this->request->allowMethod('post');
        $this->assertManager();

        $propertyId = $this->effectivePropertyId();
        if ($propertyId === null) {
            throw new BadRequestException('property_id is required.');
        }

        $charges = $this->fetchTable('ExtraCharges');
        $charge = $charges->newEntity([
            'property_id' => $propertyId,
            'code' => null,
            'name' => $this->request->getData('name'),
            'amount' => $this->request->getData('amount') ?? 0,
            'is_active' => (bool)($this->request->getData('is_active') ?? true),
        ]);

        if (!$charges->save($charge)) {
            $this->validationFailed($charge->getErrors());

            return;
        }

        $this->response = $this->response->withStatus(201);
        $this->set('extraCharge', $charge);
        $this->viewBuilder()->setOption('serialize', ['extraCharge']);
    }

    /**
     * PATCH/PUT /api/extra-charges/{id} — set amount/name/active.
     * The `code` of a built-in row is never changed.
     */
    public function edit(int $id): void
    {
        $this->request->allowMethod(['patch', 'put', 'post']);
        $this->assertManager();

        $charges = $this->fetchTable('ExtraCharges');
        $charge = $this->scopeToProperty($charges->find()->where(['ExtraCharges.id' => $id]))->firstOrFail();

        $data = [
            'amount' => $this->request->getData('amount'),
            'is_active' => (bool)$this->request->getData('is_active'),
        ];
        // A built-in charge keeps its name; only custom charges can be renamed.
        if ($charge->code === null && $this->request->getData('name') !== null) {
            $data['name'] = $this->request->getData('name');
        }

        $charges->patchEntity($charge, $data, ['accessibleFields' => ['property_id' => false, 'code' => false]]);

        if (!$charges->save($charge)) {
            $this->validationFailed($charge->getErrors());

            return;
        }

        $this->set('extraCharge', $charge);
        $this->viewBuilder()->setOption('serialize', ['extraCharge']);
    }

    /**
     * DELETE /api/extra-charges/{id} — custom charges only.
     */
    public function delete(int $id): void
    {
        $this->request->allowMethod('delete');
        $this->assertManager();

        $charges = $this->fetchTable('ExtraCharges');
        $charge = $this->scopeToProperty($charges->find()->where(['ExtraCharges.id' => $id]))->firstOrFail();

        if ($charge->code === ExtraCharge::CODE_EARLY_CHECK_IN) {
            throw new BadRequestException('The early check-in charge is built in and cannot be deleted.');
        }

        $charges->deleteOrFail($charge);

        $this->set('deleted', true);
        $this->viewBuilder()->setOption('serialize', ['deleted']);
    }

    private function assertManager(): void
    {
        if (!$this->userHasRole('owner', 'admin')) {
            throw new ForbiddenException('Only owners and admins may manage extra charges.');
        }
    }

    private function validationFailed(array $errors): void
    {
        $this->response = $this->response->withStatus(422);
        $this->set('errors', $errors);
        $this->viewBuilder()->setOption('serialize', ['errors']);
    }
}
