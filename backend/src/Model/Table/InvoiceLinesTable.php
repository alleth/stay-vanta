<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;

/**
 * InvoiceLines model.
 *
 * @method \App\Model\Entity\InvoiceLine newEmptyEntity()
 */
class InvoiceLinesTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('invoice_lines');
        $this->setDisplayField('description');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp', [
            'events' => ['Model.beforeSave' => ['created' => 'new']],
        ]);

        $this->belongsTo('Invoices');
    }
}
