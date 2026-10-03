<?php
declare(strict_types=1);

namespace App\Event;

use Cake\Database\Connection;
use Cake\Log\Log;

/**
 * Indexes history that predates the event foundation (build step 5, part 3),
 * so the Operations feed can read activity_index alone.
 *
 * Follows the backfill rule (CLAUDE.md, docs/EVENTS.md):
 * - idempotent: everything already recorded is skipped, by key, so a second
 *   run adds nothing and rows written live since part 2 are never doubled;
 * - auditable: rows are marked `source = 'import'` with correlation id
 *   `import-<table>-<id>`, and check() logs per-property counts;
 * - repeatable: batched by id range, each batch its own transaction, and the
 *   same routine runs from the migration and from `bin/cake activity_backfill`;
 * - it never invents a fact: no actor role, no reason, no time (a row with no
 *   `created` is left out and counted), no status "as it was" (unknown), and
 *   ledger rows themselves are never changed (stock_movements keeps its nulls;
 *   only its index row says `import-`).
 *
 * Plain SQL through the connection: these are bulk inserts into append-only
 * tables, which refuse the ORM's update/delete paths but not inserts.
 */
final class ActivityBackfill
{
    public const BATCH = 1000;

    /**
     * @param \Cake\Database\Connection $connection The application database.
     */
    public function __construct(private Connection $connection)
    {
    }

    /**
     * Backfill everything not yet indexed; optionally one property only.
     *
     * @param int|null $propertyId Limit to one property (tests, targeted re-runs).
     * @return array<string, int> Rows added per step.
     */
    public function run(?int $propertyId = null): array
    {
        $added = [
            // Events first: index rows are made from them.
            'placed_events' => $this->inBatches('food_orders', $propertyId, $this->placedEventsSql()),
            'order_index' => $this->inBatches('food_order_events', $propertyId, $this->orderIndexSql()),
            'stock_index' => $this->inBatches('stock_movements', $propertyId, $this->stockIndexSql()),
            'invoice_opened_events' => $this->inBatches('invoices', $propertyId, $this->invoiceOpenedSql()),
            'invoice_line_events' => $this->inBatches('invoice_lines', $propertyId, $this->invoiceLineSql()),
            'invoice_settled_events' => $this->inBatches('invoices', $propertyId, $this->invoiceSettledSql()),
            'invoice_index' => $this->inBatches('invoice_events', $propertyId, $this->invoiceIndexSql()),
        ];
        // Auditable: what this run added (anything already recorded was skipped).
        $parts = [];
        foreach ($added as $what => $count) {
            $parts[] = "$what=$count";
        }
        Log::info(sprintf(
            'activity backfill run%s: added %s',
            $propertyId !== null ? " (property $propertyId)" : '',
            implode(', ', $parts),
        ));

        return $added;
    }

    /**
     * Compare what exists with what's indexed, per property, and log it:
     * `info` when complete, `warning` when anything is missing.
     *
     * Rows with no recorded time are left out of the backfill and counted
     * here as undated, never given an invented time.
     *
     * @return array<int, array<string, int|bool>> Counts per property id, plus `complete`.
     */
    public function check(?int $propertyId = null): array
    {
        $where = $propertyId !== null ? 'WHERE p.id = ' . $propertyId : '';
        $rows = $this->connection->execute(
            "SELECT p.id,
                (SELECT COUNT(*) FROM stock_movements m WHERE m.property_id = p.id) AS stock,
                (SELECT COUNT(*) FROM stock_movements m WHERE m.property_id = p.id
                    AND m.created IS NULL AND m.occurred_at IS NULL) AS stock_undated,
                (SELECT COUNT(*) FROM activity_index a WHERE a.property_id = p.id
                    AND a.event_table = 'stock_movements') AS stock_indexed,
                (SELECT COUNT(*) FROM food_orders o WHERE o.property_id = p.id) AS orders,
                (SELECT COUNT(*) FROM food_orders o WHERE o.property_id = p.id AND o.created IS NULL) AS orders_undated,
                (SELECT COUNT(*) FROM food_order_events e WHERE e.property_id = p.id
                    AND e.event_type = 'placed') AS placed,
                (SELECT COUNT(*) FROM activity_index a WHERE a.property_id = p.id
                    AND a.event_table = 'food_order_events' AND a.event_type = 'placed') AS placed_indexed,
                (SELECT COUNT(*) FROM invoices i WHERE i.property_id = p.id) AS invoices,
                (SELECT COUNT(*) FROM invoices i WHERE i.property_id = p.id AND i.created IS NOT NULL
                    AND NOT EXISTS (SELECT 1 FROM invoice_events e WHERE e.invoice_id = i.id
                        AND e.event_type = 'opened')) AS invoices_unopened,
                (SELECT COUNT(*) FROM invoices i WHERE i.property_id = p.id AND i.created IS NULL) AS invoices_undated,
                (SELECT COUNT(*) FROM invoice_lines l JOIN invoices i ON i.id = l.invoice_id
                    WHERE i.property_id = p.id AND l.reverses_line_id IS NULL AND l.created IS NOT NULL
                    AND NOT EXISTS (SELECT 1 FROM invoice_events e WHERE e.invoice_line_id = l.id)) AS lines_unrecorded,
                (SELECT COUNT(*) FROM invoice_lines l JOIN invoices i ON i.id = l.invoice_id
                    WHERE i.property_id = p.id AND l.created IS NULL) AS lines_undated,
                (SELECT COUNT(*) FROM invoices i WHERE i.property_id = p.id AND i.status = 'settled'
                    AND i.settled_at IS NOT NULL
                    AND NOT EXISTS (SELECT 1 FROM invoice_events e WHERE e.invoice_id = i.id
                        AND e.event_type IN ('settled', 'settled_on_creation'))) AS settled_unrecorded,
                (SELECT COUNT(*) FROM invoices i WHERE i.property_id = p.id AND i.status = 'settled'
                    AND i.settled_at IS NULL) AS settled_undated,
                (SELECT COUNT(*) FROM invoice_events e WHERE e.property_id = p.id
                    AND NOT EXISTS (SELECT 1 FROM activity_index a WHERE a.event_table = 'invoice_events'
                        AND a.event_id = e.id)) AS invoice_unindexed
            FROM properties p $where ORDER BY p.id",
        )->fetchAll('assoc');

        $result = [];
        foreach ($rows as $row) {
            $counts = array_map('intval', $row);
            $id = $counts['id'];
            unset($counts['id']);

            $complete = $counts['stock_indexed'] === $counts['stock'] - $counts['stock_undated']
                && $counts['placed'] === $counts['orders'] - $counts['orders_undated']
                && $counts['placed_indexed'] === $counts['placed']
                && $counts['invoices_unopened'] === 0
                && $counts['lines_unrecorded'] === 0
                && $counts['settled_unrecorded'] === 0
                && $counts['invoice_unindexed'] === 0;
            $result[$id] = $counts + ['complete' => $complete];

            $line = sprintf(
                'activity backfill check, property %d: stock %d/%d indexed (%d undated), orders %d/%d placed, '
                . '%d/%d indexed (%d undated); invoices %d: %d unopened, %d lines unrecorded, '
                . '%d settled unrecorded, %d events unindexed (undated: %d invoices, %d lines, %d settlements)',
                $id,
                $counts['stock_indexed'],
                $counts['stock'],
                $counts['stock_undated'],
                $counts['placed'],
                $counts['orders'],
                $counts['placed_indexed'],
                $counts['placed'],
                $counts['orders_undated'],
                $counts['invoices'],
                $counts['invoices_unopened'],
                $counts['lines_unrecorded'],
                $counts['settled_unrecorded'],
                $counts['invoice_unindexed'],
                $counts['invoices_undated'],
                $counts['lines_undated'],
                $counts['settled_undated'],
            );
            $complete ? Log::info($line) : Log::warning($line . ' — INCOMPLETE');
        }

        return $result;
    }

    /**
     * Run one insert per id range of `$table`, each in its own transaction.
     *
     * @param string $sql Insert with :from and :to, and `<alias>__PROPERTY__` where a property filter goes.
     * @return int Rows inserted.
     */
    private function inBatches(string $table, ?int $propertyId, string $sql): int
    {
        $bounds = $this->connection->execute("SELECT MIN(id) AS lo, MAX(id) AS hi FROM $table")->fetch('assoc');
        if (!$bounds || $bounds['lo'] === null) {
            return 0;
        }
        $inserted = 0;
        for ($from = (int)$bounds['lo']; $from <= (int)$bounds['hi']; $from += self::BATCH) {
            $params = ['from' => $from, 'to' => $from + self::BATCH - 1];
            $batchSql = preg_replace_callback(
                '/(\w)__PROPERTY__/',
                fn(array $m): string => $propertyId === null ? '' : "AND {$m[1]}.property_id = :property",
                $sql,
            );
            if ($propertyId !== null) {
                $params['property'] = $propertyId;
            }
            $inserted += $this->connection->transactional(
                fn(Connection $c): int => $c->execute($batchSql, $params)->rowCount(),
            );
        }

        return $inserted;
    }

    /**
     * A `placed` event for every order without one. The actor is the stored
     * receptionist_id; role, reason and the order's status at the time
     * weren't recorded, so they're left out.
     */
    private function placedEventsSql(): string
    {
        return "INSERT INTO food_order_events
                (property_id, food_order_id, event_type, actor_id, actor_role, source, reason, changes,
                 snapshot, correlation_id, occurred_at, corrects_event_id, amount, created)
            SELECT o.property_id, o.id, 'placed', o.receptionist_id, NULL, 'import', NULL, NULL,
                JSON_OBJECT('total', o.total, 'room', r.room_number, 'guest_id', o.guest_id, 'guest_name', g.full_name),
                CONCAT('import-food_orders-', o.id), o.created, NULL, o.total, UTC_TIMESTAMP()
            FROM food_orders o
            LEFT JOIN rooms r ON r.id = o.room_id
            LEFT JOIN guests g ON g.id = o.guest_id
            WHERE o.id BETWEEN :from AND :to
                o__PROPERTY__
                AND o.created IS NOT NULL
                AND NOT EXISTS (
                    SELECT 1 FROM food_order_events e WHERE e.food_order_id = o.id AND e.event_type = 'placed'
                )";
    }

    /**
     * An index row for every POS event without one (imported ones; live ones
     * were indexed when recorded).
     */
    private function orderIndexSql(): string
    {
        return "INSERT INTO activity_index
                (property_id, occurred_at, actor_id, subject_type, subject_id, event_table, event_id,
                 event_type, correlation_id, summary, created)
            SELECT e.property_id, e.occurred_at, e.actor_id, 'food_order', e.food_order_id,
                'food_order_events', e.id, e.event_type, e.correlation_id, e.snapshot, UTC_TIMESTAMP()
            FROM food_order_events e
            WHERE e.id BETWEEN :from AND :to
                e__PROPERTY__
                AND NOT EXISTS (
                    SELECT 1 FROM activity_index a WHERE a.event_table = 'food_order_events' AND a.event_id = e.id
                )";
    }

    /**
     * An index row for every stock movement without one. The movement row
     * itself is not touched.
     */
    private function stockIndexSql(): string
    {
        return "INSERT INTO activity_index
                (property_id, occurred_at, actor_id, subject_type, subject_id, event_table, event_id,
                 event_type, correlation_id, summary, created)
            SELECT m.property_id, COALESCE(m.occurred_at, m.created), m.receptionist_id, 'inventory_item',
                m.inventory_item_id, 'stock_movements', m.id,
                CASE WHEN m.direction = 'out' THEN 'moved_out' ELSE 'moved_in' END,
                COALESCE(m.correlation_id, CONCAT('import-stock_movements-', m.id)),
                JSON_OBJECT('direction', m.direction, 'quantity', m.quantity, 'item', i.name, 'unit', i.unit,
                    'reason', m.reason),
                UTC_TIMESTAMP()
            FROM stock_movements m
            LEFT JOIN inventory_items i ON i.id = m.inventory_item_id
            WHERE m.id BETWEEN :from AND :to
                m__PROPERTY__
                AND COALESCE(m.occurred_at, m.created) IS NOT NULL
                AND NOT EXISTS (
                    SELECT 1 FROM activity_index a WHERE a.event_table = 'stock_movements' AND a.event_id = m.id
                )";
    }

    /**
     * An `opened` event for every invoice without one, at its `created`. Who
     * opened it was never recorded: no actor.
     */
    private function invoiceOpenedSql(): string
    {
        return "INSERT INTO invoice_events
                (property_id, invoice_id, event_type, actor_id, actor_role, source, reason, changes, snapshot,
                 correlation_id, occurred_at, corrects_event_id, amount, total_after, invoice_line_id,
                 invoice_number, or_number, created)
            SELECT i.property_id, i.id, 'opened', NULL, NULL, 'import', NULL, NULL,
                JSON_OBJECT('guest_id', i.guest_id, 'guest_name', g.full_name, 'reservation_id', i.reservation_id),
                CONCAT('import-invoices-', i.id), i.created, NULL, NULL, NULL, NULL, NULL, NULL, UTC_TIMESTAMP()
            FROM invoices i
            LEFT JOIN guests g ON g.id = i.guest_id
            WHERE i.id BETWEEN :from AND :to
                i__PROPERTY__
                AND i.created IS NOT NULL
                AND NOT EXISTS (SELECT 1 FROM invoice_events e WHERE e.invoice_id = i.id AND e.event_type = 'opened')";
    }

    /**
     * A `line_added` event for every original line without an event, at the
     * line's `created`, with its amount. The total after it isn't recorded
     * (lines deleted before step 6 make any running sum unreliable): NULL.
     */
    private function invoiceLineSql(): string
    {
        return "INSERT INTO invoice_events
                (property_id, invoice_id, event_type, actor_id, actor_role, source, reason, changes, snapshot,
                 correlation_id, occurred_at, corrects_event_id, amount, total_after, invoice_line_id,
                 invoice_number, or_number, created)
            SELECT i.property_id, l.invoice_id, 'line_added', NULL, NULL, 'import', NULL,
                JSON_OBJECT('line', JSON_OBJECT('description', l.description,
                    'source', CONCAT(COALESCE(l.source_type, ''), ':', COALESCE(l.source_id, '')))),
                NULL, CONCAT('import-invoice_lines-', l.id), l.created, NULL, l.amount, NULL, l.id,
                NULL, NULL, UTC_TIMESTAMP()
            FROM invoice_lines l
            JOIN invoices i ON i.id = l.invoice_id
            WHERE l.id BETWEEN :from AND :to
                i__PROPERTY__
                AND l.created IS NOT NULL
                AND l.reverses_line_id IS NULL
                AND NOT EXISTS (SELECT 1 FROM invoice_events e WHERE e.invoice_line_id = l.id)";
    }

    /**
     * A `settled` event for every settled invoice without one, at its
     * `settled_at`, with its receipt numbers. The amount at that moment isn't
     * known for sure (lines could change a settled invoice before step 6): NULL.
     */
    private function invoiceSettledSql(): string
    {
        return "INSERT INTO invoice_events
                (property_id, invoice_id, event_type, actor_id, actor_role, source, reason, changes, snapshot,
                 correlation_id, occurred_at, corrects_event_id, amount, total_after, invoice_line_id,
                 invoice_number, or_number, created)
            SELECT i.property_id, i.id, 'settled', NULL, NULL, 'import', NULL,
                JSON_OBJECT('status', JSON_ARRAY('open', 'settled')),
                JSON_OBJECT('guest_id', i.guest_id, 'guest_name', g.full_name, 'reservation_id', i.reservation_id),
                CONCAT('import-invoices-', i.id, '-settled'), i.settled_at, NULL, NULL, NULL, NULL,
                i.invoice_number, i.or_number, UTC_TIMESTAMP()
            FROM invoices i
            LEFT JOIN guests g ON g.id = i.guest_id
            WHERE i.id BETWEEN :from AND :to
                i__PROPERTY__
                AND i.status = 'settled'
                AND i.settled_at IS NOT NULL
                AND NOT EXISTS (SELECT 1 FROM invoice_events e WHERE e.invoice_id = i.id
                    AND e.event_type IN ('settled', 'settled_on_creation'))";
    }

    /**
     * An index row for every invoice event without one.
     */
    private function invoiceIndexSql(): string
    {
        return "INSERT INTO activity_index
                (property_id, occurred_at, actor_id, subject_type, subject_id, event_table, event_id,
                 event_type, correlation_id, summary, created)
            SELECT e.property_id, e.occurred_at, e.actor_id, 'invoice', e.invoice_id,
                'invoice_events', e.id, e.event_type, e.correlation_id, e.snapshot, UTC_TIMESTAMP()
            FROM invoice_events e
            WHERE e.id BETWEEN :from AND :to
                e__PROPERTY__
                AND NOT EXISTS (
                    SELECT 1 FROM activity_index a WHERE a.event_table = 'invoice_events' AND a.event_id = e.id
                )";
    }
}
