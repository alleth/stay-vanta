<?php
declare(strict_types=1);

namespace App\Controller\Api;

use Cake\Http\Exception\BadRequestException;

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
     */
    public function settle(int $id): void
    {
        $this->request->allowMethod('post');
        $invoices = $this->fetchTable('Invoices');
        $invoice = $this->scopeToProperty($invoices->find()->where(['Invoices.id' => $id]))->firstOrFail();

        if ($invoice->status !== 'open') {
            throw new BadRequestException('Invoice is not open.');
        }
        $invoice->set('status', 'settled');
        $invoice->set('settled_at', \Cake\I18n\DateTime::now());
        $invoices->saveOrFail($invoice);

        $this->set('invoice', $invoice);
        $this->viewBuilder()->setOption('serialize', ['invoice']);
    }
}
