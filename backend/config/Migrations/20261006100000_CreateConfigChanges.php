<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Build step 9: configuration accountability (docs/EVENTS.md, config_changes).
 *
 * - `config_changes`: one shared ledger of every change to a configuration
 *   row (room rates, promo rates, booking sources, extra charges, rooms,
 *   receipt booklets, the menu, inventory categories, property records):
 *   who, when, why, every changed field before/after, and its impact class
 *   (price, booking, operational, administrative). Written only by
 *   ConfigAuditBehavior, inside the save's transaction.
 * - `deleted_at` on the configuration tables that were hard-deleted, so a
 *   deletion keeps the row (and its `deleted` change keeps every value).
 *
 * Additive only: the previous code ignores both. No foreign keys and no
 * ON DELETE CASCADE: changes outlive their subject.
 */
class CreateConfigChanges extends BaseMigration
{
    /**
     * @return void
     */
    public function change(): void
    {
        $this->table('config_changes')
            ->addColumn('property_id', 'integer', ['null' => false])
            ->addColumn('entity_type', 'string', ['limit' => 40, 'null' => false])
            ->addColumn('entity_id', 'integer', ['null' => false])
            ->addColumn('event_type', 'string', ['limit' => 40, 'null' => false])
            ->addColumn('impact', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('actor_id', 'integer', ['null' => true])
            ->addColumn('actor_role', 'string', ['limit' => 20, 'null' => true])
            ->addColumn('source', 'string', ['limit' => 10, 'null' => false])
            ->addColumn('reason', 'text', ['null' => true])
            ->addColumn('changes', 'json', ['null' => true])
            ->addColumn('snapshot', 'json', ['null' => true])
            ->addColumn('correlation_id', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('occurred_at', 'datetime', ['null' => false])
            ->addColumn('corrects_event_id', 'integer', ['null' => true])
            ->addColumn('created', 'datetime', ['null' => true])
            ->addIndex(['property_id', 'occurred_at'])
            ->addIndex(['entity_type', 'entity_id', 'id'])
            ->addIndex(['property_id', 'impact', 'occurred_at'])
            ->addIndex(['property_id', 'correlation_id'])
            ->create();

        foreach (['promo_rates', 'extra_charges', 'rooms', 'receipt_series', 'inventory_categories'] as $table) {
            $this->table($table)
                ->addColumn('deleted_at', 'datetime', ['null' => true])
                ->update();
        }
    }
}
