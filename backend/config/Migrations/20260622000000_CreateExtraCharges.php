<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * extra_charges — admin-configurable surcharges a property can bill (e.g. an
 * early check-in fee). One built-in, non-deletable row per property carries the
 * fixed `code` 'early_check_in'; admins set its amount. Custom charges have a
 * null code. The check-in flow looks up the early-check-in amount and posts it
 * as an invoice line when a guest checks in before the standard time.
 */
class CreateExtraCharges extends BaseMigration
{
    public function change(): void
    {
        $this->table('extra_charges')
            ->addColumn('property_id', 'integer', ['null' => false])
            ->addColumn('code', 'string', ['limit' => 50, 'null' => true]) // 'early_check_in' | null (custom)
            ->addColumn('name', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('amount', 'decimal', ['precision' => 10, 'scale' => 2, 'null' => false, 'default' => 0])
            ->addColumn('is_active', 'boolean', ['null' => false, 'default' => true])
            ->addColumn('created', 'datetime', ['null' => true])
            ->addColumn('modified', 'datetime', ['null' => true])
            ->addIndex(['property_id'])
            ->create();
    }
}
