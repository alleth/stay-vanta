<?php
declare(strict_types=1);

namespace App\Event;

use Cake\Database\Connection;
use Cake\Log\Log;
use Cake\ORM\TableRegistry;

/**
 * The configuration baseline (build step 9, C7): one `baseline_recorded`
 * change for every configuration row that has none, holding its values as
 * they stand now, dated now (the import), with no actor and no reason.
 * Configuration rows only ever stored `created`/`modified`: who made them,
 * their earlier values and rows deleted before step 9 were never recorded,
 * so nothing earlier is invented.
 *
 * Follows the backfill rule (CLAUDE.md): idempotent (a row with any change
 * is skipped, so a second run adds nothing and live changes are never
 * doubled); auditable (`source = 'import'`, correlation id
 * `import-config-<entity>-<id>`, a logged per-property check); repeatable
 * (`bin/cake activity_backfill`, one transaction per table).
 */
final class ConfigBaseline
{
    /** Table alias => entity type, every table ConfigAuditBehavior covers. */
    public const TABLES = [
        'Properties' => 'property',
        'RoomRates' => 'room_rate',
        'PromoRates' => 'promo_rate',
        'BookingSources' => 'booking_source',
        'ExtraCharges' => 'extra_charge',
        'Rooms' => 'room',
        'ReceiptSeries' => 'receipt_series',
        'FoodMenuItems' => 'menu_item',
        'InventoryCategories' => 'inventory_category',
    ];

    /**
     * @param \Cake\Database\Connection $connection The application database.
     */
    public function __construct(private Connection $connection)
    {
    }

    /**
     * Record a baseline for every configuration row without any change.
     *
     * @param int|null $propertyId Limit to one property.
     * @return array<string, int> Baselines added per entity type.
     */
    public function run(?int $propertyId = null): array
    {
        $added = [];
        if (!$this->hasLedger()) {
            return $added;
        }
        $locator = TableRegistry::getTableLocator();
        foreach (self::TABLES as $alias => $entityType) {
            $table = $locator->get($alias);
            $propertyColumn = $alias === 'Properties' ? 'id' : 'property_id';
            $query = $table->find('all', withDeleted: true)
                ->where(function ($exp, $q) use ($table, $entityType) {
                    return $exp->notExists(
                        $q->getConnection()->selectQuery('1', 'config_changes')
                            ->where([
                                'config_changes.entity_type' => $entityType,
                                'config_changes.entity_id = ' . $table->aliasField('id'),
                            ]),
                    );
                });
            if ($propertyId !== null) {
                $query->where([$table->aliasField($propertyColumn) => $propertyId]);
            }
            $rows = $query->all()->toList();
            $this->connection->transactional(function () use ($table, $rows, $entityType): void {
                foreach ($rows as $row) {
                    $context = new EventContext(
                        null,
                        null,
                        null,
                        "import-config-$entityType-" . $row->get('id'),
                        EventContext::SOURCE_IMPORT,
                    );
                    $table->recordBaseline($context, $row);
                }
            });
            $added[$entityType] = count($rows);
        }

        $parts = [];
        foreach ($added as $type => $count) {
            $parts[] = "$type=$count";
        }
        Log::info(sprintf(
            'config baseline run%s: added %s',
            $propertyId !== null ? " (property $propertyId)" : '',
            implode(', ', $parts),
        ));

        return $added;
    }

    /**
     * Per property: configuration rows, rows with no recorded change (must be
     * zero), baselines imported, and changes recorded since. Logged: `info`
     * when complete, `warning` otherwise.
     *
     * @param int|null $propertyId Limit to one property.
     * @return array<int, array<string, int|bool>>
     */
    public function check(?int $propertyId = null): array
    {
        if (!$this->hasLedger()) {
            return [];
        }
        $where = $propertyId !== null ? 'WHERE p.id = ' . (int)$propertyId : '';
        $parts = [];
        foreach (self::TABLES as $alias => $entityType) {
            $tableName = TableRegistry::getTableLocator()->get($alias)->getTable();
            $propertyColumn = $alias === 'Properties' ? 'id' : 'property_id';
            $parts[] = "(SELECT COUNT(*) FROM $tableName t WHERE t.$propertyColumn = p.id) AS {$entityType}_rows,
                (SELECT COUNT(*) FROM $tableName t WHERE t.$propertyColumn = p.id
                    AND NOT EXISTS (SELECT 1 FROM config_changes c WHERE c.entity_type = '$entityType'
                        AND c.entity_id = t.id)) AS {$entityType}_unrecorded";
        }
        $rows = $this->connection->execute(
            'SELECT p.id, ' . implode(",\n", $parts) . ",
                (SELECT COUNT(*) FROM config_changes c WHERE c.property_id = p.id
                    AND c.event_type = 'baseline_recorded') AS baselines,
                (SELECT COUNT(*) FROM config_changes c WHERE c.property_id = p.id
                    AND c.event_type != 'baseline_recorded') AS recorded_changes
            FROM properties p $where ORDER BY p.id",
        )->fetchAll('assoc');

        $result = [];
        foreach ($rows as $row) {
            $counts = array_map('intval', $row);
            $id = $counts['id'];
            unset($counts['id']);
            $configRows = 0;
            $unrecorded = 0;
            foreach (self::TABLES as $entityType) {
                $configRows += $counts["{$entityType}_rows"];
                $unrecorded += $counts["{$entityType}_unrecorded"];
            }
            $complete = $unrecorded === 0;
            $result[$id] = $counts + [
                'config_rows' => $configRows,
                'unrecorded' => $unrecorded,
                'complete' => $complete,
            ];
            $line = sprintf(
                'config baseline check, property %d: %d configuration rows, %d with no recorded change; '
                . '%d baselines imported, %d changes recorded since',
                $id,
                $configRows,
                $unrecorded,
                $counts['baselines'],
                $counts['recorded_changes'],
            );
            $complete ? Log::info($line) : Log::warning($line . ' — INCOMPLETE');
        }

        return $result;
    }

    /**
     * Whether config_changes exists yet (a fresh database runs migrations in order).
     */
    private function hasLedger(): bool
    {
        return in_array('config_changes', $this->connection->getSchemaCollection()->listTables(), true);
    }
}
