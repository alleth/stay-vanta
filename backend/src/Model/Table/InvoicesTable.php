<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Event\EventContext;
use App\Model\Entity\Invoice;
use App\Model\Entity\InvoiceLine;
use Cake\I18n\DateTime;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use RuntimeException;

/**
 * Invoices model, and since build step 6 the only way an invoice changes.
 *
 * Every change follows the ledger rule (CLAUDE.md "Lock, then check, then
 * change, then record"): inside one transaction the invoice row is locked
 * (FOR UPDATE), checked, changed, and the change recorded in invoice_events
 * with the request's EventContext. The rules it enforces:
 * - lines are never deleted: a cancelled line gets a negative line pointing
 *   at it (`reverses_line_id`), and the total is the sum of all lines;
 * - a settled invoice never changes, with one documented exception: a
 *   downpayment refund (recordRefund(), to be revisited in step 7);
 * - settling happens once: a second settle is refused and takes no receipt
 *   number.
 * A guest has at most one `open` invoice per property; charges append to it.
 *
 * @method \App\Model\Entity\Invoice newEmptyEntity()
 * @method \App\Model\Entity\Invoice get(mixed $primaryKey, array $options = [])
 */
class InvoicesTable extends Table
{
    /**
     * @param array<string, mixed> $config Table config.
     * @return void
     */
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
     * Find the guest's open invoice, opening one if there's none. The guest
     * row is locked first, so two requests can't open two invoices for one
     * guest.
     */
    public function openInvoiceFor(
        EventContext $context,
        int $propertyId,
        int $guestId,
        ?int $reservationId = null,
    ): Invoice {
        return $this->getConnection()->transactional(
            function () use ($context, $propertyId, $guestId, $reservationId): Invoice {
                TableRegistry::getTableLocator()->get('Guests')->find()
                    ->where(['id' => $guestId])->epilog('FOR UPDATE')->first();

                /** @var \App\Model\Entity\Invoice|null $invoice */
                $invoice = $this->find()
                    ->where(['property_id' => $propertyId, 'guest_id' => $guestId, 'status' => 'open'])
                    ->first();
                if ($invoice !== null) {
                    return $invoice;
                }

                return $this->open($context, $propertyId, $guestId, $reservationId);
            },
        );
    }

    /**
     * An invoice settled on the spot — money collected right now, e.g. an
     * advance-booking downpayment. Recorded as opened, its line added, and
     * settled_on_creation; it counts as collected immediately (by settled_at).
     */
    public function settledInvoiceWith(
        EventContext $context,
        int $propertyId,
        int $guestId,
        ?int $reservationId,
        string $description,
        float $amount,
        string $sourceType,
        int $sourceId,
    ): Invoice {
        return $this->getConnection()->transactional(
            function () use (
                $context,
                $propertyId,
                $guestId,
                $reservationId,
                $description,
                $amount,
                $sourceType,
                $sourceId,
            ): Invoice {
                $invoice = $this->open($context, $propertyId, $guestId, $reservationId);
                $this->addLine($context, $invoice, $description, $amount, $sourceType, $sourceId);

                $invoice = $this->lock($invoice);
                $invoice->set('status', 'settled');
                $invoice->set('settled_at', DateTime::now());
                $this->saveOrFail($invoice);
                $this->events()->record($context, InvoiceEventsTable::SETTLED_ON_CREATION, $invoice, [
                    'changes' => ['status' => ['open', 'settled']],
                    'columns' => ['amount' => $invoice->total, 'total_after' => $invoice->total],
                ]);

                return $invoice;
            },
        );
    }

    /**
     * The invoice holding a line in force from the given source, if any (a
     * reversed line doesn't count: its charge is no longer on the invoice).
     */
    public function invoiceForLine(string $sourceType, int $sourceId): ?Invoice
    {
        $line = $this->InvoiceLines->find('active')
            ->where(['InvoiceLines.source_type' => $sourceType, 'InvoiceLines.source_id' => $sourceId])
            ->first();

        return $line ? $this->get($line->invoice_id) : null;
    }

    /**
     * Append a line to an open invoice and recompute its total from all its
     * lines. Refused on a settled invoice.
     */
    public function addLine(
        EventContext $context,
        Invoice $invoice,
        string $description,
        float $amount,
        string $sourceType,
        int $sourceId,
    ): InvoiceLine {
        return $this->getConnection()->transactional(
            function () use ($context, $invoice, $description, $amount, $sourceType, $sourceId): InvoiceLine {
                $locked = $this->lock($invoice);
                $this->assertOpen($locked);

                $line = $this->insertLine($locked, $description, $amount, $sourceType, $sourceId);
                $this->retotal($locked, false);
                $this->events()->record($context, InvoiceEventsTable::LINE_ADDED, $locked, [
                    'columns' => [
                        'amount' => $amount,
                        'invoice_line_id' => $line->id,
                        'total_after' => $locked->total,
                    ],
                    'changes' => ['line' => ['description' => $description, 'source' => "$sourceType:$sourceId"]],
                ]);
                $invoice->set('total', $locked->total);

                return $line;
            },
        );
    }

    /**
     * The one permitted change to a settled invoice (decided 2026-10-03, to be
     * revisited in step 7): a cancelled advance booking's downpayment refund,
     * as a negative line on the downpayment invoice, recorded as
     * refund_recorded with who, the amount and the total after.
     */
    public function recordRefund(
        EventContext $context,
        Invoice $invoice,
        string $description,
        float $amount,
        int $reservationId,
    ): InvoiceLine {
        if ($amount >= 0) {
            throw new RuntimeException('A refund is a negative amount.');
        }

        return $this->getConnection()->transactional(
            function () use ($context, $invoice, $description, $amount, $reservationId): InvoiceLine {
                $locked = $this->lock($invoice);
                $line = $this->insertLine($locked, $description, $amount, 'downpayment_refund', $reservationId);
                $this->retotal($locked, false);
                $this->events()->record($context, InvoiceEventsTable::REFUND_RECORDED, $locked, [
                    'columns' => [
                        'amount' => $amount,
                        'invoice_line_id' => $line->id,
                        'total_after' => $locked->total,
                    ],
                    'changes' => ['line' => [
                        'description' => $description,
                        'source' => "downpayment_refund:$reservationId",
                    ]],
                ]);
                $invoice->set('total', $locked->total);

                return $line;
            },
        );
    }

    /**
     * Reverse every line in force from a source: because its business was
     * cancelled (a sale, a reservation's charges; the default type), or by a
     * Manager's decision (LINE_REVERSED, with the context's reason; e.g.
     * Front Desk's Reverse room charge). Refused if any of them is on a
     * settled invoice.
     *
     * @return int Lines reversed.
     */
    public function reverseLinesFor(
        EventContext $context,
        string $sourceType,
        int $sourceId,
        string $type = InvoiceEventsTable::LINE_REVERSED_ON_CANCEL,
    ): int {
        return $this->getConnection()->transactional(function () use ($context, $sourceType, $sourceId, $type): int {
            $lines = $this->InvoiceLines->find('active')
                ->where(['InvoiceLines.source_type' => $sourceType, 'InvoiceLines.source_id' => $sourceId])
                ->all();
            foreach ($lines as $line) {
                $this->reverseLine($context, $line, $type);
            }

            return $lines->count();
        });
    }

    /**
     * Reverse one line: a negative line pointing at it, the total recomputed
     * (never below zero, as before), and the reversal recorded. Refused on a
     * settled invoice, on a line already reversed, and on a reversal itself.
     *
     * @param string $type InvoiceEventsTable::LINE_REVERSED (by hand, needs a
     *   reason) or LINE_REVERSED_ON_CANCEL.
     */
    public function reverseLine(EventContext $context, InvoiceLine $line, string $type): InvoiceLine
    {
        return $this->getConnection()->transactional(function () use ($context, $line, $type): InvoiceLine {
            $invoice = $this->lock($this->get($line->invoice_id));
            $this->assertOpen($invoice);

            $lines = $this->InvoiceLines;
            /** @var \App\Model\Entity\InvoiceLine $fresh */
            $fresh = $lines->get($line->id);
            if ($fresh->reverses_line_id !== null) {
                throw new RuntimeException('A reversal line can\'t itself be reversed.');
            }
            if ($lines->exists(['reverses_line_id' => $fresh->id])) {
                throw new RuntimeException('This line has already been reversed.');
            }

            $reversal = $this->insertLine(
                $invoice,
                'Reversed: ' . $fresh->description,
                -(float)$fresh->amount,
                'reversal',
                (int)$fresh->id,
                (int)$fresh->id,
            );
            $this->retotal($invoice, true);
            $this->events()->record($context, $type, $invoice, [
                'columns' => [
                    'amount' => -(float)$fresh->amount,
                    'invoice_line_id' => $reversal->id,
                    'total_after' => $invoice->total,
                ],
                'changes' => ['reverses' => [
                    'line_id' => $fresh->id,
                    'description' => $fresh->description,
                    'source' => $fresh->source_type . ':' . $fresh->source_id,
                ]],
            ]);

            return $reversal;
        });
    }

    /**
     * Settle an open invoice once: assign its receipt numbers (each booklet
     * locked as it's used), mark it settled and record who did it. A second
     * settle is refused and takes no receipt number.
     */
    public function settle(EventContext $context, Invoice $invoice, bool $useInvoice, bool $useOr): Invoice
    {
        return $this->getConnection()->transactional(
            function () use ($context, $invoice, $useInvoice, $useOr): Invoice {
                $locked = $this->lock($invoice);
                if ($locked->status !== 'open') {
                    throw new RuntimeException('Invoice is not open.');
                }

                /** @var \App\Model\Table\ReceiptSeriesTable $series */
                $series = TableRegistry::getTableLocator()->get('ReceiptSeries');
                if ($useInvoice) {
                    $locked->set('invoice_number', $series->assignNext((int)$locked->property_id, 'invoice'));
                }
                if ($useOr) {
                    $locked->set('or_number', $series->assignNext((int)$locked->property_id, 'official_receipt'));
                }
                $locked->set('status', 'settled');
                $locked->set('settled_at', DateTime::now());
                $this->saveOrFail($locked);
                $this->events()->record($context, InvoiceEventsTable::SETTLED, $locked, [
                    'changes' => ['status' => ['open', 'settled']],
                    'columns' => [
                        'amount' => $locked->total,
                        'total_after' => $locked->total,
                        'invoice_number' => $locked->invoice_number,
                        'or_number' => $locked->or_number,
                    ],
                ]);

                return $locked;
            },
        );
    }

    /**
     * Create an open invoice and record it.
     */
    private function open(EventContext $context, int $propertyId, int $guestId, ?int $reservationId): Invoice
    {
        $invoice = $this->newEntity([
            'property_id' => $propertyId,
            'guest_id' => $guestId,
            'reservation_id' => $reservationId,
            'status' => 'open',
            'total' => 0,
        ]);
        $this->saveOrFail($invoice);
        $this->events()->record($context, InvoiceEventsTable::OPENED, $invoice);

        return $invoice;
    }

    /**
     * Re-read the invoice with a FOR UPDATE lock inside the caller's
     * transaction (never trust an entity loaded before it).
     */
    private function lock(Invoice $invoice): Invoice
    {
        /** @var \App\Model\Entity\Invoice $locked */
        $locked = $this->find()->where(['Invoices.id' => $invoice->id])->epilog('FOR UPDATE')->firstOrFail();

        return $locked;
    }

    /**
     * Refuse any change to a settled invoice.
     */
    private function assertOpen(Invoice $invoice): void
    {
        if ($invoice->status !== 'open') {
            throw new RuntimeException('This invoice is settled, so it can no longer change.');
        }
    }

    /**
     * Store a line (never deleted afterwards); a reversal names the line it answers.
     */
    private function insertLine(
        Invoice $invoice,
        string $description,
        float $amount,
        string $sourceType,
        int $sourceId,
        ?int $reversesLineId = null,
    ): InvoiceLine {
        $lines = $this->InvoiceLines;
        /** @var \App\Model\Entity\InvoiceLine $line */
        $line = $lines->newEntity([
            'invoice_id' => $invoice->id,
            'description' => $description,
            'amount' => $amount,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
        ]);
        $line->set('reverses_line_id', $reversesLineId);

        return $lines->saveOrFail($line);
    }

    /**
     * Set the total to the sum of all the invoice's lines (originals and
     * reversals). Reversals floor it at zero, as removing lines always did.
     */
    private function retotal(Invoice $invoice, bool $floorAtZero): void
    {
        $query = $this->InvoiceLines->find()->where(['invoice_id' => $invoice->id]);
        $sum = $query->select(['s' => $query->func()->sum('amount')])->disableHydration()->first();
        $total = round((float)($sum['s'] ?? 0), 2);
        $invoice->set('total', $floorAtZero ? max(0, $total) : $total);
        $this->saveOrFail($invoice);
    }

    /**
     * The invoice ledger.
     */
    private function events(): InvoiceEventsTable
    {
        /** @var \App\Model\Table\InvoiceEventsTable $events */
        $events = TableRegistry::getTableLocator()->get('InvoiceEvents');

        return $events;
    }
}
