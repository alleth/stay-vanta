<?php
declare(strict_types=1);

namespace App\Controller\Api;

use Cake\Http\Exception\BadRequestException;
use Cake\I18n\DateTime;
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
        $invoices = $this->fetchTable('Invoices');
        $query = $this->scopeToProperty(
            $invoices->find()->contain(['Guests', 'InvoiceLines'])->orderBy(['Invoices.created' => 'DESC'])
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
            $next = date('Y-m-d', strtotime($date . ' +1 day'));
            $query->where(function (\Cake\Database\Expression\QueryExpression $exp) use ($date, $next) {
                return $exp->or([
                    'Invoices.status' => 'open',
                    $exp->and([
                        'Invoices.created >=' => $date . ' 00:00:00',
                        'Invoices.created <' => $next . ' 00:00:00',
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
        $invoices = $this->fetchTable('Invoices');
        $invoice = $this->scopeToProperty($invoices->find()->where(['Invoices.id' => $id]))->firstOrFail();

        if ($invoice->status !== 'open') {
            throw new BadRequestException('Invoice is not open.');
        }

        $useInvoice = (bool)$this->request->getData('use_invoice');
        $useOr = (bool)$this->request->getData('use_or');
        $series = $this->fetchTable('ReceiptSeries');

        try {
            $invoices->getConnection()->transactional(
                function () use ($invoices, $invoice, $series, $useInvoice, $useOr): void {
                    if ($useInvoice) {
                        $invoice->set('invoice_number', $series->assignNext((int)$invoice->property_id, 'invoice'));
                    }
                    if ($useOr) {
                        $invoice->set('or_number', $series->assignNext((int)$invoice->property_id, 'official_receipt'));
                    }
                    $invoice->set('status', 'settled');
                    $invoice->set('settled_at', DateTime::now());
                    $invoices->saveOrFail($invoice);
                },
            );
        } catch (RuntimeException $e) {
            // e.g. no active series / booklet exhausted.
            throw new BadRequestException($e->getMessage());
        }

        $this->set('invoice', $invoice);
        $this->viewBuilder()->setOption('serialize', ['invoice']);
    }
}
