<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Model\Table\ReceiptSeriesTable;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\ForbiddenException;

/**
 * Receipt booklet series — the pre-printed physical Sales Invoice and
 * Official Receipt booklets a property registers into the system. Settling
 * an invoice can consume the next number (see InvoicesController::settle).
 */
class ReceiptSeriesController extends AppController
{
    /**
     * GET /api/receipt-series
     */
    public function index(): void
    {
        $table = $this->fetchTable('ReceiptSeries');
        $query = $this->scopeToProperty(
            $table->find()->orderBy(['ReceiptSeries.type' => 'ASC', 'ReceiptSeries.id' => 'ASC']),
        );

        $this->set('series', $query->all());
        $this->viewBuilder()->setOption('serialize', ['series']);
    }

    /**
     * POST /api/receipt-series  (owner/admin)
     * { type: invoice|official_receipt, prefix?, start_number, end_number, is_active? }
     *
     * Zero-padding follows how the start was typed ("0001" → width 4).
     */
    public function add(): void
    {
        $this->request->allowMethod('post');

        if (!$this->userHasRole('owner', 'admin')) {
            throw new ForbiddenException('Only owners and admins may register receipt series.');
        }
        $propertyId = $this->effectivePropertyId();
        if ($propertyId === null) {
            throw new BadRequestException('property_id is required.');
        }

        $table = $this->fetchTable('ReceiptSeries');
        $start = trim((string)($this->request->getData('start_number') ?? ''));
        if (!ctype_digit($start)) {
            throw new BadRequestException('Start number must be numeric.');
        }

        $entity = $table->newEntity([
            'type' => $this->request->getData('type'),
            'prefix' => trim((string)($this->request->getData('prefix') ?? '')) ?: null,
            'start_number' => (int)$start,
            'end_number' => (int)($this->request->getData('end_number') ?? 0),
            'is_active' => (bool)($this->request->getData('is_active') ?? true),
        ]);
        $entity->set('property_id', $propertyId);
        $entity->set('next_number', (int)$start);
        $entity->set('pad_length', strlen($start));

        if (!$table->save($entity)) {
            $this->response = $this->response->withStatus(422);
            $this->set('errors', $entity->getErrors());
            $this->viewBuilder()->setOption('serialize', ['errors']);

            return;
        }

        $this->set('series', $entity);
        $this->viewBuilder()->setOption('serialize', ['series']);
    }

    /**
     * PATCH/PUT /api/receipt-series/{id}  (owner/admin) — activate/deactivate.
     */
    public function edit(int $id): void
    {
        $this->request->allowMethod(['patch', 'put', 'post']);

        if (!$this->userHasRole('owner', 'admin')) {
            throw new ForbiddenException('Only owners and admins may edit receipt series.');
        }

        $table = $this->fetchTable('ReceiptSeries');
        $series = $this->scopeToProperty($table->find()->where(['ReceiptSeries.id' => $id]))->firstOrFail();

        $active = $this->request->getData('is_active');
        if ($active !== null) {
            $series->set('is_active', (bool)$active);
        }
        $table->saveOrFail($series);

        $this->set('series', $series);
        $this->viewBuilder()->setOption('serialize', ['series']);
    }

    /**
     * DELETE /api/receipt-series/{id}  (owner/admin)
     *
     * Refused once the series has issued numbers — those numbers are on real
     * settled invoices; deactivate the series instead.
     */
    public function delete(int $id): void
    {
        $this->request->allowMethod(['delete', 'post']);

        if (!$this->userHasRole('owner', 'admin')) {
            throw new ForbiddenException('Only owners and admins may delete receipt series.');
        }

        $table = $this->fetchTable('ReceiptSeries');
        $series = $this->scopeToProperty($table->find()->where(['ReceiptSeries.id' => $id]))->firstOrFail();

        if ((int)$series->get('next_number') > (int)$series->get('start_number')) {
            $label = ReceiptSeriesTable::TYPE_LABELS[$series->get('type')] ?? 'series';
            throw new BadRequestException(
                sprintf('This %s series has issued numbers; deactivate it instead of deleting.', $label),
            );
        }

        $table->deleteOrFail($series);

        $this->set('ok', true);
        $this->viewBuilder()->setOption('serialize', ['ok']);
    }
}
