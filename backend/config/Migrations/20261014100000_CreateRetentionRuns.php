<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Retention routine (final review G5, approved 2026-10-09 as P1–P6).
 *
 * - `redacted_at` on access_events and guest_events: when the routine
 *   cleared that event's personal values. It tells "recorded and later
 *   cleared" apart from "never recorded".
 * - `retention_runs`: one append-only row per policy per run (dry runs
 *   too): which policy, its cutoff, how many rows it cleared (or would
 *   have), and the run's request id.
 *
 * Additive only: the previous code ignores all of it. Nothing is cleared
 * by this migration.
 */
class CreateRetentionRuns extends BaseMigration
{
    /**
     * @return void
     */
    public function change(): void
    {
        foreach (['access_events', 'guest_events'] as $table) {
            $this->table($table)
                ->addColumn('redacted_at', 'datetime', ['null' => true])
                ->update();
        }

        $this->table('retention_runs')
            ->addColumn('policy', 'string', ['limit' => 40, 'null' => false])
            ->addColumn('table_name', 'string', ['limit' => 40, 'null' => false])
            ->addColumn('cutoff', 'datetime', ['null' => false])
            ->addColumn('rows_cleared', 'integer', ['null' => false, 'default' => 0])
            ->addColumn('dry_run', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('property_id', 'integer', ['null' => true])
            ->addColumn('correlation_id', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('occurred_at', 'datetime', ['null' => false])
            ->addColumn('created', 'datetime', ['null' => true])
            ->addIndex(['policy', 'occurred_at'])
            ->create();
    }
}
