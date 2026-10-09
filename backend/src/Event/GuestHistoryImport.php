<?php
declare(strict_types=1);

namespace App\Event;

use App\Model\Table\GuestEventsTable;
use Cake\Database\Connection;
use Cake\Log\Log;
use Cake\ORM\TableRegistry;

/**
 * Guest history before G3 (approved 2026-10-09): one `imported` event per
 * guest that has none, dated at the guest's own `created`, with no actor and
 * no reason. When a guest was registered was recorded; who registered them,
 * and every later edit, weren't, so none of that is made up. A guest whose
 * `modified` is later than its `created` was changed by someone unrecorded:
 * the snapshot says so (`changed_since_registration`), nothing more.
 *
 * Follows the backfill rule (CLAUDE.md): idempotent (a guest with any guest
 * event is skipped); auditable (`source = 'import'`, correlation
 * `import-guests-<id>`, a logged per-property check); repeatable
 * (`bin/cake activity_backfill`, batched, each batch its own transaction).
 * A guest with no creation time is reported and left out.
 */
final class GuestHistoryImport
{
    private const BATCH = 200;

    /**
     * @param \Cake\Database\Connection $connection The application database.
     */
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @param int|null $propertyId Limit to one property's guests.
     * @return array{imported: int, skipped: int} What was added, and what couldn't be.
     */
    public function run(?int $propertyId = null): array
    {
        $result = ['imported' => 0, 'skipped' => 0];
        if (!$this->hasLedger()) {
            return $result;
        }
        $locator = TableRegistry::getTableLocator();
        /** @var \App\Model\Table\GuestEventsTable $events */
        $events = $locator->get('GuestEvents');
        $query = $locator->get('Guests')->find()
            ->where(function ($exp, $q) {
                return $exp->notExists(
                    $q->getConnection()->selectQuery('1', 'guest_events')
                        ->where(['guest_events.guest_id = Guests.id']),
                );
            })
            ->orderBy(['Guests.id' => 'ASC']);
        if ($propertyId !== null) {
            $query->where(['Guests.property_id' => $propertyId]);
        }

        $skipped = [];
        foreach (array_chunk($query->all()->toList(), self::BATCH) as $batch) {
            $this->connection->transactional(function () use ($batch, $events, &$result, &$skipped): void {
                foreach ($batch as $guest) {
                    $created = $guest->get('created');
                    if ($created === null) {
                        $skipped[] = (int)$guest->get('id');
                        continue;
                    }
                    $modified = $guest->get('modified');
                    $context = new EventContext(
                        null,
                        null,
                        (int)$guest->get('property_id'),
                        'import-guests-' . $guest->get('id'),
                        EventContext::SOURCE_IMPORT,
                        null,
                        $created,
                    );
                    $events->record($context, GuestEventsTable::IMPORTED, $guest, [
                        'snapshot' => [
                            'changed_since_registration' => $modified !== null && $modified > $created,
                        ],
                    ]);
                    $result['imported']++;
                }
            });
        }
        $result['skipped'] = count($skipped);

        Log::info(sprintf(
            'guest history import run%s: %d imported',
            $propertyId !== null ? " (property $propertyId)" : '',
            $result['imported'],
        ));
        if ($skipped !== []) {
            Log::warning(sprintf(
                'guest history import: %d guest(s) with no creation time not imported: ids %s',
                count($skipped),
                implode(', ', $skipped),
            ));
        }

        return $result;
    }

    /**
     * Per property: guests, guests with no event at all (must be zero, or
     * reported), imported events and events recorded since. Logged: `info`
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
        $rows = $this->connection->execute(
            "SELECT p.id,
                (SELECT COUNT(*) FROM guests g WHERE g.property_id = p.id) AS guests,
                (SELECT COUNT(*) FROM guests g WHERE g.property_id = p.id AND NOT EXISTS
                    (SELECT 1 FROM guest_events e WHERE e.guest_id = g.id)) AS unrecorded,
                (SELECT COUNT(*) FROM guest_events e WHERE e.property_id = p.id
                    AND e.source = 'import') AS imported,
                (SELECT COUNT(*) FROM guest_events e WHERE e.property_id = p.id
                    AND e.source != 'import') AS recorded_since
            FROM properties p $where ORDER BY p.id",
        )->fetchAll('assoc');

        $result = [];
        foreach ($rows as $row) {
            $counts = array_map('intval', $row);
            $id = $counts['id'];
            unset($counts['id']);
            $counts['complete'] = $counts['unrecorded'] === 0;
            $result[$id] = $counts;
            $line = sprintf(
                'guest history check, property %d: %d guests, %d with no history; %d imported, %d recorded since',
                $id,
                $counts['guests'],
                $counts['unrecorded'],
                $counts['imported'],
                $counts['recorded_since'],
            );
            $counts['complete'] ? Log::info($line) : Log::warning($line);
        }

        return $result;
    }

    /**
     * Whether the ledger exists yet (a fresh database migrates in order).
     */
    private function hasLedger(): bool
    {
        return in_array('guest_events', $this->connection->getSchemaCollection()->listTables(), true);
    }
}
