<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Model\Entity\Invoice;
use Cake\ORM\Table;

/**
 * Invoices model. A guest has at most one `open` invoice per property at a
 * time; charge-to-room food orders append lines to it.
 *
 * @method \App\Model\Entity\Invoice newEmptyEntity()
 * @method \App\Model\Entity\Invoice get(mixed $primaryKey, array $options = [])
 */
class InvoicesTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('invoices');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');

        $this->belongsTo('Properties');
        $this->belongsTo('Guests');
        $this->hasMany('InvoiceLines');
    }

    /**
     * Find the guest's current open invoice, creating one if needed.
     */
    public function openInvoiceFor(int $propertyId, int $guestId, ?int $reservationId = null): Invoice
    {
        $invoice = $this->find()
            ->where(['property_id' => $propertyId, 'guest_id' => $guestId, 'status' => 'open'])
            ->first();

        if ($invoice === null) {
            $invoice = $this->newEntity([
                'property_id' => $propertyId,
                'guest_id' => $guestId,
                'reservation_id' => $reservationId,
                'status' => 'open',
                'total' => 0,
            ]);
            $this->saveOrFail($invoice);
        }

        return $invoice;
    }

    /**
     * Append a line and bump the invoice total.
     */
    public function addLine(Invoice $invoice, string $description, float $amount, string $sourceType, int $sourceId): void
    {
        $lines = $this->InvoiceLines;
        $line = $lines->newEntity([
            'invoice_id' => $invoice->id,
            'description' => $description,
            'amount' => $amount,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
        ]);
        $lines->saveOrFail($line);

        $invoice->set('total', (float)$invoice->total + $amount);
        $this->saveOrFail($invoice);
    }

    /**
     * Remove any lines tied to a source (e.g. a cancelled food order) and
     * reduce the invoice total accordingly.
     */
    public function removeLinesFor(string $sourceType, int $sourceId): void
    {
        $lines = $this->InvoiceLines;
        $matching = $lines->find()
            ->where(['source_type' => $sourceType, 'source_id' => $sourceId])
            ->all();

        foreach ($matching as $line) {
            $invoice = $this->get($line->invoice_id);
            $invoice->set('total', max(0, (float)$invoice->total - (float)$line->amount));
            $this->saveOrFail($invoice);
            $lines->delete($line);
        }
    }
}
