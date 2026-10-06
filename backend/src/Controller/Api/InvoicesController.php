<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Auth\Permissions;
use App\Model\BusinessTime;
use App\Model\Finance\DuplicateRefundException;
use App\Model\Table\InvoiceEventsTable;
use Cake\Database\Expression\QueryExpression;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\ConflictException;
use Cake\Http\Response;
use RuntimeException;

/**
 * Invoices — guest tabs. Charge-to-room food orders append lines here.
 */
class InvoicesController extends AppController
{
    /**
     * GET /api/invoices[?guest_id=NN][?status=open]
     */
    public function index(): void
    {
        $this->authorize(Permissions::FINANCE_INVOICE_VIEW);
        $invoices = $this->fetchTable('Invoices');
        $query = $this->scopeToProperty(
            $invoices->find()->contain(['Guests', 'InvoiceLines'])->orderBy(['Invoices.created' => 'DESC']),
        );

        if (($guestId = $this->request->getQuery('guest_id')) !== null) {
            $query->where(['Invoices.guest_id' => (int)$guestId]);
        }
        if (($status = $this->request->getQuery('status')) !== null) {
            $query->where(['Invoices.status' => $status]);
        }

        // Day filter: each day is a fresh start, but an OPEN tab always shows
        // (an unsettled invoice from a previous day must not disappear).
        $date = $this->request->getQuery('date');
        if ($date !== null && $date !== 'all' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            // The hotel's day, not UTC's (see BusinessTime).
            $from = BusinessTime::startOf($date);
            $to = BusinessTime::endOf($date);
            $query->where(function (QueryExpression $exp) use ($from, $to) {
                return $exp->or([
                    'Invoices.status' => 'open',
                    $exp->and([
                        'Invoices.created >=' => $from,
                        'Invoices.created <' => $to,
                    ]),
                ]);
            });
        }

        $list = $query->all()->toList();
        // Who settled each settled invoice (build step 6), from its ledger.
        $settledBy = $this->settledBy(array_map(fn($i) => (int)$i->id, $list));
        foreach ($list as $invoice) {
            $invoice->set('settled_by', $settledBy[(int)$invoice->id] ?? null);
        }

        $this->set('invoices', $list);
        $this->viewBuilder()->setOption('serialize', ['invoices']);
    }

    /**
     * GET /api/invoices/export?from=YYYY-MM-DD&to=YYYY-MM-DD → CSV
     *
     * One row per invoice line, for invoices opened in the range (step 10c,
     * Manager): reversals appear as their own negative lines, as on the
     * folio, so the lines of an invoice add up to its total. Recorded as
     * `data_exported`.
     */
    public function export(): Response
    {
        $this->request->allowMethod('get');
        $this->authorize(Permissions::FINANCE_INVOICE_EXPORT, 'Only Managers can export invoices.');
        $range = $this->exportRange();
        $invoices = $this->scopeToProperty($this->fetchTable('Invoices')->find())
            ->contain([
                'Guests' => fn($q) => $q->select(['id', 'full_name']),
                'InvoiceLines' => fn($q) => $q->orderBy(['InvoiceLines.id' => 'ASC']),
            ])
            ->where([
                'Invoices.created >=' => BusinessTime::startOf($range[0]),
                'Invoices.created <' => BusinessTime::endOf($range[1]),
            ])
            ->orderBy(['Invoices.created' => 'ASC', 'Invoices.id' => 'ASC'])
            ->all();

        $local = fn($at) => $at?->setTimezone(BusinessTime::timezone())->format('Y-m-d H:i');
        $cells = [];
        foreach ($invoices as $invoice) {
            foreach ($invoice->invoice_lines ?? [] as $line) {
                $cells[] = [
                    (int)$invoice->id,
                    $invoice->invoice_number,
                    $invoice->or_number,
                    $invoice->guest?->full_name,
                    $invoice->status === 'settled' ? 'Settled' : 'Open',
                    $local($invoice->created),
                    $local($invoice->settled_at),
                    $line->description,
                    $line->source_type,
                    round((float)$line->amount, 2),
                    $line->reverses_line_id !== null ? 'Reversal' : null,
                ];
                if (count($cells) > self::EXPORT_MAX_ROWS) {
                    break 2;
                }
            }
        }

        return $this->respondWithCsv('invoices', $range, [
            'Invoice', 'SI number', 'OR number', 'Guest', 'Status', 'Opened', 'Settled', 'Line', 'Source',
            'Amount', 'Note',
        ], $cells);
    }

    /**
     * GET /api/invoices/{id} — invoice with its lines.
     */
    public function view(int $id): void
    {
        $this->authorize(Permissions::FINANCE_INVOICE_VIEW);
        $invoices = $this->fetchTable('Invoices');
        $invoice = $this->scopeToProperty($invoices->find()->where(['Invoices.id' => $id]))
            ->contain(['Guests', 'InvoiceLines'])
            ->firstOrFail();
        $history = $this->history((int)$invoice->id);
        $invoice->set('history', $history);
        $invoice->set('settled_by', $this->settledBy([(int)$invoice->id])[(int)$invoice->id] ?? null);
        // Money returned against it (step 7c): its own records, never lines.
        $invoice->set('refunds', array_values(array_filter(
            $history,
            fn($e) => in_array($e['event'], InvoiceEventsTable::REFUND_TYPES, true),
        )));
        $invoice->set('refundable', $invoices->refundable($invoice));

        $this->set('invoice', $invoice);
        $this->viewBuilder()->setOption('serialize', ['invoice']);
    }

    /**
     * The invoice's ledger, oldest first, as the folio shows it: what
     * happened, when, who (null and `recorded: false` for history imported
     * from before step 6, never a guess), amount, total after, the line it
     * concerns, the reason and receipt numbers.
     *
     * @return list<array<string, mixed>>
     */
    private function history(int $invoiceId): array
    {
        $events = $this->fetchTable('InvoiceEvents')->find()
            ->where(['invoice_id' => $invoiceId])->orderBy(['occurred_at' => 'ASC', 'id' => 'ASC'])->all()->toList();
        $names = $this->actorNames($events);

        return array_map(fn($e) => [
            'id' => (int)$e->id,
            'event' => $e->event_type,
            'at' => $e->occurred_at,
            'actor' => $e->actor_id !== null ? ($names[$e->actor_id] ?? null) : null,
            'recorded' => $e->source !== 'import',
            'amount' => $e->amount !== null ? round((float)$e->amount, 2) : null,
            'total_after' => $e->total_after !== null ? round((float)$e->total_after, 2) : null,
            'line_id' => $e->invoice_line_id !== null ? (int)$e->invoice_line_id : null,
            'reverses_line_id' => isset($e->changes['reverses']['line_id'])
                ? (int)$e->changes['reverses']['line_id'] : null,
            'reason' => $e->reason,
            'method' => $e->method,
            'invoice_number' => $e->invoice_number,
            'or_number' => $e->or_number,
        ], $events);
    }

    /**
     * Who settled each of these invoices (their `settled` or
     * `settled_on_creation` event): `{name, recorded, at}`.
     *
     * @param list<int> $invoiceIds Invoice ids.
     * @return array<int, array<string, mixed>>
     */
    private function settledBy(array $invoiceIds): array
    {
        if ($invoiceIds === []) {
            return [];
        }
        $events = $this->fetchTable('InvoiceEvents')->find()
            ->where(['invoice_id IN' => $invoiceIds, 'event_type IN' => ['settled', 'settled_on_creation']])
            ->all()->toList();
        $names = $this->actorNames($events);
        $result = [];
        foreach ($events as $e) {
            $result[(int)$e->invoice_id] = [
                'name' => $e->actor_id !== null ? ($names[$e->actor_id] ?? null) : null,
                'recorded' => $e->source !== 'import',
                'at' => $e->occurred_at,
            ];
        }

        return $result;
    }

    /**
     * Current names of the people behind these events.
     *
     * @param list<\Cake\Datasource\EntityInterface> $events Ledger rows.
     * @return array<int, string>
     */
    private function actorNames(array $events): array
    {
        $ids = array_values(array_unique(array_filter(array_map(fn($e) => $e->actor_id, $events))));

        return $ids === [] ? [] : $this->fetchTable('Users')->find()
            ->select(['id', 'name'])->where(['id IN' => $ids])->all()->combine('id', 'name')->toArray();
    }

    /**
     * POST /api/invoices/{id}/settle — close out a paid tab.
     *
     * Optional body { use_invoice?: bool, use_or?: bool }: when set, the next
     * number is consumed from the property's active Physical Invoice /
     * Official Receipt series and stamped onto the invoice (invoice_number /
     * or_number), mirroring the physical document handed to the guest.
     */
    public function settle(int $id): void
    {
        $this->request->allowMethod('post');
        $this->authorize(Permissions::FINANCE_INVOICE_SETTLE);
        /** @var \App\Model\Table\InvoicesTable $invoices */
        $invoices = $this->fetchTable('Invoices');
        $invoice = $this->scopeToProperty($invoices->find()->where(['Invoices.id' => $id]))->firstOrFail();

        // Locked, checked, settled and recorded (who, numbers) in one
        // transaction; a second settle is refused and takes no number.
        try {
            $invoice = $invoices->settle(
                $this->eventContext(),
                $invoice,
                (bool)$this->request->getData('use_invoice'),
                (bool)$this->request->getData('use_or'),
            );
        } catch (RuntimeException $e) {
            // Already settled, or no active booklet / booklet exhausted.
            throw new BadRequestException($e->getMessage());
        }

        $this->set('invoice', $invoice);
        $this->viewBuilder()->setOption('serialize', ['invoice']);
    }

    /**
     * POST /api/invoices/{id}/lines/{lineId}/reverse  { reason }
     *
     * Reverse a line on an open invoice (finance.invoice.reverse, a Manager,
     * with a reason): a negative line pointing at it is added, the total
     * recomputed, and the reversal recorded with who and why. Lines are never
     * deleted. Refused on a settled invoice, on a line already reversed and
     * on a reversal itself.
     */
    public function reverseLine(int $id, int $lineId): void
    {
        $this->request->allowMethod('post');
        $this->authorizeElevated(Permissions::FINANCE_INVOICE_REVERSE, 'Only a Manager can reverse an invoice line.');
        /** @var \App\Model\Table\InvoicesTable $invoices */
        $invoices = $this->fetchTable('Invoices');
        $invoice = $this->scopeToProperty($invoices->find()->where(['Invoices.id' => $id]))->firstOrFail();
        /** @var \App\Model\Entity\InvoiceLine $line */
        $line = $invoices->InvoiceLines->find()
            ->where(['InvoiceLines.id' => $lineId, 'InvoiceLines.invoice_id' => $invoice->id])
            ->firstOrFail();

        try {
            $invoices->reverseLine($this->eventContext(), $line, InvoiceEventsTable::LINE_REVERSED);
        } catch (RuntimeException $e) {
            throw new BadRequestException($e->getMessage());
        }

        $this->set('invoice', $invoices->get($invoice->id, contain: ['Guests', 'InvoiceLines']));
        $this->viewBuilder()->setOption('serialize', ['invoice']);
    }

    /**
     * POST /api/invoices/{id}/refund  { amount, method, reason, refund_key? }
     *
     * Money returned against a settled invoice (finance.invoice.refund, a
     * Manager, with a reason; build step 7c): recorded as a `refunded` event,
     * cash out today. The invoice never changes. Refused (400) on an open
     * invoice, a non-positive amount, an unknown method or more than is
     * refundable; a repeat of the same request (same `refund_key`) is 409 and
     * records nothing. Returns the invoice as GET /invoices/{id} does.
     */
    public function refund(int $id): void
    {
        $this->request->allowMethod('post');
        $this->authorizeElevated(Permissions::FINANCE_INVOICE_REFUND, 'Only a Manager can refund an invoice.');
        /** @var \App\Model\Table\InvoicesTable $invoices */
        $invoices = $this->fetchTable('Invoices');
        $invoice = $this->scopeToProperty($invoices->find()->where(['Invoices.id' => $id]))->firstOrFail();

        $amount = $this->request->getData('amount');
        if (!is_numeric($amount)) {
            throw new BadRequestException('Enter the amount returned to the guest.');
        }
        $key = $this->request->getData('refund_key');
        if ($key !== null && (!is_string($key) || $key === '' || strlen($key) > 64)) {
            throw new BadRequestException('refund_key must be a string of up to 64 characters.');
        }

        try {
            $invoices->refund(
                $this->eventContext(),
                $invoice,
                (float)$amount,
                (string)$this->request->getData('method'),
                InvoiceEventsTable::REFUNDED,
                $key,
            );
        } catch (DuplicateRefundException $e) {
            throw new ConflictException($e->getMessage());
        } catch (RuntimeException $e) {
            throw new BadRequestException($e->getMessage());
        }

        $this->view($id);
    }
}
