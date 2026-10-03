<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\Database\Expression\QueryExpression;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\Table;

/**
 * InvoiceLines model.
 *
 * @method \App\Model\Entity\InvoiceLine newEmptyEntity()
 */
class InvoiceLinesTable extends Table
{
    /**
     * @param array<string, mixed> $config Table config.
     * @return void
     */
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

    /**
     * Lines still in force: neither a reversal nor reversed. A cancelled line
     * stays on the invoice (lines are never deleted) and is answered by a
     * negative line with `reverses_line_id`; "is this charge posted?" checks
     * must look past both.
     *
     * @param \Cake\ORM\Query\SelectQuery $query The query.
     * @return \Cake\ORM\Query\SelectQuery
     */
    public function findActive(SelectQuery $query): SelectQuery
    {
        $alias = $this->getAlias();

        return $query
            ->where(["$alias.reverses_line_id IS" => null])
            ->where(fn(QueryExpression $exp) => $exp->add(
                "NOT EXISTS (SELECT 1 FROM invoice_lines rv WHERE rv.reverses_line_id = $alias.id)",
            ));
    }
}
