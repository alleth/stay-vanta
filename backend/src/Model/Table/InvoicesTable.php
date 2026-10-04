<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Event\EventContext;
use App\Model\Entity\Invoice;
use App\Model\Entity\InvoiceLine;
use App\Model\Finance\DuplicateRefundException;
use Cake\Datasource\EntityInterface;
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
 * - a settled invoice never changes; money given back is a refund event
 *   (refund(), build step 7c), never a line;
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
     * How money goes back to a guest (the POS payment methods).
     */
    public const REFUND_METHODS = FoodOrdersTable::PAYMENT_METHODS;

    /**
     * Money returned against a settled invoice (build step 7c): cash out on
     * the day it happens. Recorded as its own event (`refunded`, a Manager
     * with a reason; or `refunded_on_cancel`, the downpayment policy refund)
     * with the amount (stored negative) and method. The invoice itself never
     * changes: no line, no new total. Locked, checked against the locked
     * row, recorded, in one transaction:
     * - only a settled invoice, only a positive amount, a known method;
     * - never more than the invoice still has to give back (refundable());
     * - exactly once: a repeated request (same `$key`) is refused, and an
     *   invoice gets at most one `refunded_on_cancel`.
     *
     * @param array<string, mixed> $changes What the event should say about why (e.g. the policy).
     * @throws \App\Model\Finance\DuplicateRefundException On a repeat.
     * @throws \RuntimeException When the refund isn't allowed.
     */
    public function refund(
        EventContext $context,
        Invoice $invoice,
        float $amount,
        string $method,
        string $type,
        ?string $key = null,
        array $changes = [],
    ): EntityInterface {
        $amount = round($amount, 2);
        if (!in_array($type, InvoiceEventsTable::REFUND_TYPES, true)) {
            throw new RuntimeException("'$type' is not a refund.");
        }
        if ($amount <= 0) {
            throw new RuntimeException('A refund must be more than zero.');
        }
        if (!in_array($method, self::REFUND_METHODS, true)) {
            throw new RuntimeException(
                'Choose how the money was returned: ' . implode(', ', self::REFUND_METHODS) . '.',
            );
        }

        return $this->getConnection()->transactional(
            function () use ($context, $invoice, $amount, $method, $type, $key, $changes): EntityInterface {
                $locked = $this->lock($invoice);
                $events = $this->events();
                if ($key !== null && $events->exists(['idempotency_key' => $key])) {
                    throw new DuplicateRefundException('This refund was already recorded.');
                }
                if (
                    $type === InvoiceEventsTable::REFUNDED_ON_CANCEL
                    && $events->exists(['invoice_id' => $locked->id, 'event_type' => $type])
                ) {
                    throw new DuplicateRefundException('This downpayment refund was already recorded.');
                }
                if ($locked->status !== 'settled') {
                    throw new RuntimeException('Only a settled invoice can be refunded; this one is still open.');
                }
                $refundable = $this->refundable($locked);
                if ($amount > $refundable) {
                    throw new RuntimeException(sprintf(
                        'At most %s can be refunded on this invoice.',
                        number_format($refundable, 2),
                    ));
                }

                return $events->record($context, $type, $locked, [
                    'columns' => ['amount' => -$amount, 'method' => $method, 'idempotency_key' => $key],
                    'changes' => $changes ?: null,
                ]);
            },
        );
    }

    /**
     * What a settled invoice can still give back: the cash it brought in,
     * less every refund recorded against it. For an invoice from before 7c
     * with a downpayment refund line, `total` is already net of that line and
     * the line was cash in and out alike, so this is `total` less the refund
     * events in both cases. Zero for an open invoice.
     */
    public function refundable(Invoice $invoice): float
    {
        if ($invoice->status !== 'settled') {
            return 0.0;
        }
        $query = $this->events()->find()->where([
            'invoice_id' => $invoice->id,
            'event_type IN' => InvoiceEventsTable::REFUND_TYPES,
        ]);
        $row = $query->select(['s' => $query->func()->sum('amount')])->disableHydration()->first();

        return max(0.0, round((float)$invoice->total + (float)($row['s'] ?? 0), 2));
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
