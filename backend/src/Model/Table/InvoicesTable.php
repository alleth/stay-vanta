<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Model\Entity\Invoice;
use Cake\I18n\DateTime;
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
     * Create an invoice that is settled on the spot — money collected right
     * now, e.g. an advance-booking downpayment. It lands in collected revenue
     * immediately (revenue buckets settled invoices by settled_at).
     */
    public function settledInvoiceWith(
        int $propertyId,
        int $guestId,
        ?int $reservationId,
        string $description,
        float $amount,
        string $sourceType,
        int $sourceId,
    ): Invoice {
        $invoice = $this->newEntity([
            'property_id' => $propertyId,
            'guest_id' => $guestId,
            'reservation_id' => $reservationId,
            'status' => 'settled',
            'total' => 0,
        ]);
        $invoice->set('settled_at', DateTime::now());
        $this->saveOrFail($invoice);
        $this->addLine($invoice, $description, $amount, $sourceType, $sourceId);

        return $invoice;
    }

    /**
     * The invoice holding a line from the given source, if any — e.g. find the
     * downpayment invoice of a reservation so a refund line can be appended.
     */
    public function invoiceForLine(string $sourceType, int $sourceId): ?Invoice
    {
        $line = $this->InvoiceLines->find()
            ->where(['source_type' => $sourceType, 'source_id' => $sourceId])
            ->first();

        return $line ? $this->get($line->invoice_id) : null;
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
     * Remove any lines tied to a source (e.g. a cancelled food order or
     * reservation charge) and recompute the affected invoice(s)' total from
     * their remaining lines. Recomputing from the ledger — rather than
     * subtracting each removed line's amount from the running total — keeps
     * a multi-line reversal (e.g. cancelling a booking removes both its room
     * charge and its downpayment credit) correct regardless of removal
     * order: subtracting one at a time and flooring at 0 after each step can
     * clip a legitimately-negative intermediate total and permanently lose
     * that amount, even though the final sum of remaining lines is right.
     */
    public function removeLinesFor(string $sourceType, int $sourceId): void
    {
        $lines = $this->InvoiceLines;
        $matching = $lines->find()
            ->where(['source_type' => $sourceType, 'source_id' => $sourceId])
            ->all();

        $invoiceIds = [];
        foreach ($matching as $line) {
            $invoiceIds[$line->invoice_id] = true;
            $lines->delete($line);
        }

        foreach (array_keys($invoiceIds) as $invoiceId) {
            $query = $lines->find()->where(['invoice_id' => $invoiceId]);
            $remaining = $query->select(['s' => $query->func()->sum('amount')])
                ->disableHydration()
                ->first();

            $invoice = $this->get($invoiceId);
            $invoice->set('total', max(0, round((float)($remaining['s'] ?? 0), 2)));
            $this->saveOrFail($invoice);
        }
    }
}
