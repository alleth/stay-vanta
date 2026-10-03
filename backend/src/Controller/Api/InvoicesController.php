<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Auth\Permissions;
use App\Model\BusinessTime;
use App\Model\Table\InvoiceEventsTable;
use Cake\Database\Expression\QueryExpression;
use Cake\Http\Exception\BadRequestException;
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

        $this->set('invoices', $query->all());
        $this->viewBuilder()->setOption('serialize', ['invoices']);
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

        $this->set('invoice', $invoice);
        $this->viewBuilder()->setOption('serialize', ['invoice']);
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
}
