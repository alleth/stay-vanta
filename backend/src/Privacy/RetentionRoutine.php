<?php
declare(strict_types=1);

namespace App\Privacy;

use App\Model\Table\GuestEventsTable;
use Cake\Database\Connection;
use Cake\I18n\DateTime;
use Cake\Log\Log;
use Cake\ORM\TableRegistry;
use Cake\Utility\Text;

/**
 * The retention routine (final review G5, approved 2026-10-09 as P1–P6):
 * clears personal values once their retention period has passed, and never
 * removes an event. Who acted, their role, what kind of event, which fields
 * changed, when, why and the request id all stay; only the values below are
 * cleared, and the event's `redacted_at` says when.
 *
 * Policies:
 * - `access_device_details` (A11): a sign-in's browser and reported address
 *   (`access_events.user_agent`, `client_address`), 12 months after the event.
 * - `guest_contact_values` (P1): phone, email, address and nationality in a
 *   guest event's before/after values (`guest_events.changes`), 24 months
 *   after the event. The field names stay ("phone changed: cleared"). Guest
 *   names are kept (P2); Senior/PWD names and ID numbers aren't touched (P4).
 *
 * This is the one sanctioned change to an append-only ledger (see
 * AppendOnlyTableTrait): it writes through the connection, so ordinary
 * saves of an event stay refused. Each policy of each run, dry or real, is
 * one `retention_runs` row. Idempotent: a row already cleared is skipped.
 * Batched: each batch is its own transaction.
 */
final class RetentionRoutine
{
    public const ACCESS_DEVICE_DETAILS = 'access_device_details';
    public const GUEST_CONTACT_VALUES = 'guest_contact_values';

    /** Policy => [table, months kept]. */
    public const POLICIES = [
        self::ACCESS_DEVICE_DETAILS => ['access_events', 12],
        self::GUEST_CONTACT_VALUES => ['guest_events', 24],
    ];

    /** The guest details G5 clears from guest history (never the name, P2). */
    public const GUEST_CONTACT_FIELDS = ['contact_number', 'email', 'address', 'nationality'];

    /** Guest event types whose `changes` can hold contact values. */
    private const GUEST_TYPES_WITH_VALUES = [
        GuestEventsTable::REGISTERED,
        GuestEventsTable::REGISTERED_DESPITE_MATCHES,
        GuestEventsTable::DETAILS_UPDATED,
        GuestEventsTable::DETAILS_COMPLETED,
    ];

    private const BATCH = 500;

    /**
     * @param \Cake\Database\Connection $connection The application database.
     * @param \Cake\I18n\DateTime|null $now The moment the cutoffs count back from; defaults to now.
     */
    public function __construct(private Connection $connection, private ?DateTime $now = null)
    {
    }

    /**
     * Clear (or, dry, count) every value past its retention period.
     *
     * @param bool $dryRun Count only; change nothing (still recorded as a run).
     * @param int|null $propertyId Limit to one property.
     * @return array<string, array{cutoff: string, rows: int, dry_run: bool}> Per policy.
     */
    public function run(bool $dryRun = false, ?int $propertyId = null): array
    {
        $now = $this->now ?? DateTime::now();
        $correlation = 'retention-' . Text::uuid();
        $result = [];
        foreach (self::POLICIES as $policy => [$table, $months]) {
            if (!$this->hasTable($table)) {
                continue;
            }
            $cutoff = $now->subMonths($months);
            $rows = $policy === self::ACCESS_DEVICE_DETAILS
                ? $this->clearDeviceDetails($cutoff, $now, $dryRun, $propertyId)
                : $this->clearGuestContactValues($cutoff, $now, $dryRun, $propertyId);
            // A run is dated when it really ran, even a preview of another
            // date (whose date shows only in its cutoff): never invent a time.
            $this->recordRun($policy, $table, $cutoff, $rows, $dryRun, $propertyId, $correlation, DateTime::now());
            $result[$policy] = ['cutoff' => $cutoff->format('Y-m-d H:i:s'), 'rows' => $rows, 'dry_run' => $dryRun];
            Log::info(sprintf(
                'retention %s%s: %d %s row(s) %s (cutoff %s, run %s)',
                $policy,
                $propertyId !== null ? " (property $propertyId)" : '',
                $rows,
                $table,
                $dryRun ? 'would be cleared' : 'cleared',
                $cutoff->format('Y-m-d H:i:s'),
                $correlation,
            ));
        }

        return $result;
    }

    /**
     * Per policy: rows past their cutoff still holding a value (must be zero
     * after a real run) and rows cleared so far. Logged: `info` when
     * complete, `warning` otherwise.
     *
     * @param int|null $propertyId Limit to one property.
     * @return array<string, array{due: int, cleared: int, complete: bool}>
     */
    public function check(?int $propertyId = null): array
    {
        $now = $this->now ?? DateTime::now();
        $result = [];
        foreach (self::POLICIES as $policy => [$table, $months]) {
            if (!$this->hasTable($table)) {
                continue;
            }
            $cutoff = $now->subMonths($months);
            $due = $policy === self::ACCESS_DEVICE_DETAILS
                ? count($this->dueDeviceIds($cutoff, $propertyId, PHP_INT_MAX))
                : count($this->dueGuestEvents($cutoff, $propertyId, PHP_INT_MAX));
            $where = $propertyId !== null ? ' AND property_id = ' . (int)$propertyId : '';
            $cleared = (int)$this->connection->execute(
                "SELECT COUNT(*) AS n FROM $table WHERE redacted_at IS NOT NULL$where",
            )->fetch('assoc')['n'];
            $result[$policy] = ['due' => $due, 'cleared' => $cleared, 'complete' => $due === 0];
            $line = sprintf(
                'retention check, %s: %d past the cutoff still holding values, %d cleared so far',
                $policy,
                $due,
                $cleared,
            );
            $due === 0 ? Log::info($line) : Log::warning($line);
        }

        return $result;
    }

    /**
     * Sign-in device details older than the cutoff.
     */
    private function clearDeviceDetails(DateTime $cutoff, DateTime $now, bool $dryRun, ?int $propertyId): int
    {
        $total = 0;
        do {
            $ids = $this->dueDeviceIds($cutoff, $propertyId, self::BATCH);
            if ($ids === [] || $dryRun) {
                return $dryRun ? count($this->dueDeviceIds($cutoff, $propertyId, PHP_INT_MAX)) : $total;
            }
            $this->connection->transactional(function () use ($ids, $now): void {
                $this->connection->update(
                    'access_events',
                    ['user_agent' => null, 'client_address' => null, 'redacted_at' => $now],
                    ['id IN' => $ids, 'redacted_at IS' => null],
                    ['redacted_at' => 'datetime'],
                );
            });
            $batch = count($ids);
            $total += $batch;
        } while ($batch === self::BATCH);

        return $total;
    }

    /**
     * @return list<int>
     */
    private function dueDeviceIds(DateTime $cutoff, ?int $propertyId, int $limit): array
    {
        $query = $this->connection->selectQuery('id', 'access_events')
            ->where([
                'occurred_at <' => $cutoff,
                'redacted_at IS' => null,
                'OR' => ['user_agent IS NOT' => null, 'client_address IS NOT' => null],
            ], ['occurred_at' => 'datetime'])
            ->orderBy(['id' => 'ASC'])
            ->limit($limit);
        if ($propertyId !== null) {
            $query->where(['property_id' => $propertyId]);
        }

        return array_map('intval', array_column($query->execute()->fetchAll('assoc'), 'id'));
    }

    /**
     * Contact values in guest history older than the cutoff.
     */
    private function clearGuestContactValues(DateTime $cutoff, DateTime $now, bool $dryRun, ?int $propertyId): int
    {
        if ($dryRun) {
            return count($this->dueGuestEvents($cutoff, $propertyId, PHP_INT_MAX));
        }
        $total = 0;
        do {
            $rows = $this->dueGuestEvents($cutoff, $propertyId, self::BATCH);
            if ($rows === []) {
                break;
            }
            $this->connection->transactional(function () use ($rows, $now): void {
                foreach ($rows as $id => $changes) {
                    $this->connection->update(
                        'guest_events',
                        ['changes' => self::withoutContactValues($changes), 'redacted_at' => $now],
                        ['id' => $id, 'redacted_at IS' => null],
                        ['changes' => 'json', 'redacted_at' => 'datetime'],
                    );
                }
            });
            $batch = count($rows);
            $total += $batch;
        } while ($batch === self::BATCH);

        return $total;
    }

    /**
     * Guest events past the cutoff that still hold a contact value: id => changes.
     *
     * @return array<int, array<string, mixed>>
     */
    private function dueGuestEvents(DateTime $cutoff, ?int $propertyId, int $limit): array
    {
        $query = $this->connection->selectQuery(['id', 'changes'], 'guest_events')
            ->where([
                'occurred_at <' => $cutoff,
                'redacted_at IS' => null,
                'event_type IN' => self::GUEST_TYPES_WITH_VALUES,
            ], ['occurred_at' => 'datetime'])
            ->orderBy(['id' => 'ASC']);
        if ($propertyId !== null) {
            $query->where(['property_id' => $propertyId]);
        }
        $due = [];
        foreach ($query->execute()->fetchAll('assoc') as $row) {
            $changes = json_decode((string)$row['changes'], true) ?: [];
            if (self::holdsContactValue($changes)) {
                $due[(int)$row['id']] = $changes;
                if (count($due) >= $limit) {
                    break;
                }
            }
        }

        return $due;
    }

    /**
     * Whether a guest event's changes still hold any contact value.
     *
     * @param array<string, mixed> $changes The event's changes.
     */
    public static function holdsContactValue(array $changes): bool
    {
        foreach (self::GUEST_CONTACT_FIELDS as $field) {
            if (($changes['after'][$field] ?? null) !== null) {
                return true;
            }
            if (isset($changes[$field]) && is_array($changes[$field])) {
                if (($changes[$field]['before'] ?? null) !== null || ($changes[$field]['after'] ?? null) !== null) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The same changes with every contact value cleared and every key kept,
     * so the history still says which fields changed.
     *
     * @param array<string, mixed> $changes The event's changes.
     * @return array<string, mixed>
     */
    public static function withoutContactValues(array $changes): array
    {
        foreach (self::GUEST_CONTACT_FIELDS as $field) {
            $after = $changes['after'] ?? null;
            if (is_array($after) && array_key_exists($field, $after)) {
                $changes['after'][$field] = null;
            }
            if (isset($changes[$field]) && is_array($changes[$field])) {
                foreach (['before', 'after'] as $side) {
                    if (array_key_exists($side, $changes[$field])) {
                        $changes[$field][$side] = null;
                    }
                }
            }
        }

        return $changes;
    }

    /**
     * One run of one policy, in retention_runs.
     */
    private function recordRun(
        string $policy,
        string $table,
        DateTime $cutoff,
        int $rows,
        bool $dryRun,
        ?int $propertyId,
        string $correlation,
        DateTime $now,
    ): void {
        $runs = TableRegistry::getTableLocator()->get('RetentionRuns');
        $runs->saveOrFail($runs->newEntity([
            'policy' => $policy,
            'table_name' => $table,
            'cutoff' => $cutoff,
            'rows_cleared' => $rows,
            'dry_run' => $dryRun,
            'property_id' => $propertyId,
            'correlation_id' => $correlation,
            'occurred_at' => $now,
        ], ['accessibleFields' => ['*' => true]]));
    }

    /**
     * Whether a table exists yet (a fresh database migrates in order).
     */
    private function hasTable(string $table): bool
    {
        $tables = $this->connection->getSchemaCollection()->listTables();

        return in_array($table, $tables, true) && in_array('retention_runs', $tables, true);
    }
}
