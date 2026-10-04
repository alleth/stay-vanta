<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\Datasource\EntityInterface;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;

/**
 * Invoice ledger (build step 6): who opened, added to, reversed, settled or
 * refunded each invoice, recorded by InvoicesTable in the same transaction as
 * the change, with its lock taken first (catalog: docs/EVENTS.md).
 */
class InvoiceEventsTable extends Table
{
    use AppendOnlyTableTrait;
    use EventLedgerTableTrait;

    public const OPENED = 'opened';
    public const LINE_ADDED = 'line_added';
    /** Reversed by hand: finance.invoice.reverse, with a reason. */
    public const LINE_REVERSED = 'line_reversed';
    /** Reversed because its source (a sale, a reservation) was cancelled. */
    public const LINE_REVERSED_ON_CANCEL = 'line_reversed_on_cancel';
    public const SETTLED = 'settled';
    /** Created already settled: an advance booking's downpayment. */
    public const SETTLED_ON_CREATION = 'settled_on_creation';
    /**
     * The one permitted change to a settled invoice (decided 2026-10-03, to
     * be revisited in step 7): a cancelled advance booking's downpayment
     * refund line on the downpayment invoice.
     */
    public const REFUND_RECORDED = 'refund_recorded';
    /**
     * Money returned against a settled invoice by a Manager
     * (finance.invoice.refund), with a reason and method; never a line, never
     * a change to the invoice (build step 7c, written from 7c-2).
     */
    public const REFUNDED = 'refunded';
    /**
     * The downpayment refund when an advance booking is cancelled (the
     * policy share, recorded in `changes`), with its method (from 7c-2).
     */
    public const REFUNDED_ON_CANCEL = 'refunded_on_cancel';
    /** Cash out: what Collections counts as Refunded. */
    public const REFUND_TYPES = [self::REFUNDED, self::REFUNDED_ON_CANCEL];

    public const TYPES = [
        self::OPENED,
        self::LINE_ADDED,
        self::LINE_REVERSED,
        self::LINE_REVERSED_ON_CANCEL,
        self::SETTLED,
        self::SETTLED_ON_CREATION,
        self::REFUND_RECORDED,
        self::REFUNDED,
        self::REFUNDED_ON_CANCEL,
    ];

    public const REQUIRES_REASON = [self::LINE_REVERSED, self::REFUNDED];

    public const REASON_GRACE = [];

    /**
     * @param array<string, mixed> $config Table config.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('invoice_events');
        $this->addBehavior('EventLedger', ['subjectKey' => 'invoice_id', 'subjectType' => 'invoice']);
    }

    /**
     * The invoice as it stood: guest by id and display name, its reservation,
     * state and total. No contact details.
     *
     * @param \Cake\Datasource\EntityInterface $invoice An invoice.
     * @return array<string, mixed>
     */
    public function snapshotOf(EntityInterface $invoice): array
    {
        $guest = null;
        if ($invoice->get('guest_id')) {
            $guest = TableRegistry::getTableLocator()->get('Guests')->find()->select(['full_name'])
                ->where(['id' => $invoice->get('guest_id')])->first();
        }

        return [
            'guest_id' => $invoice->get('guest_id'),
            'guest_name' => $guest?->get('full_name'),
            'reservation_id' => $invoice->get('reservation_id'),
            'status' => $invoice->get('status'),
            'total' => round((float)$invoice->get('total'), 2),
        ];
    }
}
