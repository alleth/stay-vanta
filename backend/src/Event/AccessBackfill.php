<?php
declare(strict_types=1);

namespace App\Event;

use App\Model\Table\AccessEventsTable;
use Cake\Database\Connection;
use Cake\Log\Log;
use Cake\ORM\TableRegistry;

/**
 * Account history before step 10 (decided 2026-10-05 as A13): one
 * `account_created` per account that has none, dated at the account's own
 * `created` time, with no actor and no reason. When an account was made was
 * recorded; who made it wasn't, and neither were deactivations, password
 * resets or sign-ins, so none of those are invented. An account's state at
 * the import (active or not) goes in the event's snapshot, not in an event.
 *
 * Follows the backfill rule (CLAUDE.md): idempotent (an account with an
 * `account_created` is skipped); auditable (`source = 'import'`,
 * correlation id `import-users-<id>`, a logged per-property check);
 * repeatable (`bin/cake activity_backfill`, batched, each batch its own
 * transaction). An account naming a property that doesn't exist, or with no
 * creation time, is reported and left out.
 */
final class AccessBackfill
{
    private const BATCH = 200;

    /**
     * @param \Cake\Database\Connection $connection The application database.
     */
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @param int|null $propertyId Limit to one property's accounts.
     * @return array{account_created: int, skipped: int} What was added, and what couldn't be.
     */
    public function run(?int $propertyId = null): array
    {
        $result = ['account_created' => 0, 'skipped' => 0];
        if (!$this->hasLedger()) {
            return $result;
        }
        $locator = TableRegistry::getTableLocator();
        $users = $locator->get('Users');
        /** @var \App\Model\Table\AccessEventsTable $events */
        $events = $locator->get('AccessEvents');
        $known = array_flip(array_map('intval', array_column(
            $this->connection->execute('SELECT id FROM properties')->fetchAll('assoc'),
            'id',
        )));

        $query = $users->find()
            ->where(function ($exp, $q) {
                return $exp->notExists(
                    $q->getConnection()->selectQuery('1', 'access_events')
                        ->where([
                            'access_events.subject_user_id = Users.id',
                            'access_events.event_type' => AccessEventsTable::ACCOUNT_CREATED,
                        ]),
                );
            })
            ->orderBy(['Users.id' => 'ASC']);
        if ($propertyId !== null) {
            $query->where(['Users.property_id' => $propertyId]);
        }

        $skipped = [];
        foreach (array_chunk($query->all()->toList(), self::BATCH) as $batch) {
            $this->connection->transactional(function () use ($batch, $events, $known, &$result, &$skipped): void {
                foreach ($batch as $user) {
                    $property = $user->get('property_id') !== null ? (int)$user->get('property_id') : null;
                    if (($property !== null && !isset($known[$property])) || $user->get('created') === null) {
                        $skipped[] = (int)$user->get('id');
                        continue;
                    }
                    $context = new EventContext(
                        null,
                        null,
                        $property,
                        'import-users-' . $user->get('id'),
                        EventContext::SOURCE_IMPORT,
                        null,
                        $user->get('created'),
                    );
                    $events->record($context, AccessEventsTable::ACCOUNT_CREATED, $user, [
                        'snapshot' => ['active_at_import' => (bool)$user->get('is_active')],
                    ]);
                    $result['account_created']++;
                }
            });
        }
        $result['skipped'] = count($skipped);

        Log::info(sprintf(
            'access backfill run%s: %d account_created imported',
            $propertyId !== null ? " (property $propertyId)" : '',
            $result['account_created'],
        ));
        if ($skipped !== []) {
            Log::warning(sprintf(
                'access backfill: %d account(s) not imported (no existing property or no creation time): ids %s',
                count($skipped),
                implode(', ', $skipped),
            ));
        }

        return $result;
    }

    /**
     * Per property (and the platform, key 0): accounts, accounts with no
     * `account_created` (must be zero, or reported), and imported creations.
     * Logged: `info` when complete, `warning` otherwise.
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
        $unrecorded = "NOT EXISTS (SELECT 1 FROM access_events e WHERE e.subject_user_id = u.id
            AND e.event_type = 'account_created')";
        $rows = $this->connection->execute(
            "SELECT p.id,
                (SELECT COUNT(*) FROM users u WHERE u.property_id = p.id) AS accounts,
                (SELECT COUNT(*) FROM users u WHERE u.property_id = p.id AND $unrecorded) AS unrecorded,
                (SELECT COUNT(*) FROM access_events e WHERE e.property_id = p.id
                    AND e.event_type = 'account_created' AND e.source = 'import') AS imported
            FROM properties p $where ORDER BY p.id",
        )->fetchAll('assoc');
        if ($propertyId === null) {
            $platform = $this->connection->execute(
                "SELECT 0 AS id,
                    (SELECT COUNT(*) FROM users u WHERE u.property_id IS NULL) AS accounts,
                    (SELECT COUNT(*) FROM users u WHERE u.property_id IS NULL AND $unrecorded) AS unrecorded,
                    (SELECT COUNT(*) FROM access_events e WHERE e.property_id IS NULL
                        AND e.event_type = 'account_created' AND e.source = 'import') AS imported",
            )->fetchAll('assoc');
            $rows = array_merge($platform, $rows);
        }

        $result = [];
        foreach ($rows as $row) {
            $counts = array_map('intval', $row);
            $id = $counts['id'];
            unset($counts['id']);
            $counts['complete'] = $counts['unrecorded'] === 0;
            $result[$id] = $counts;
            $line = sprintf(
                'access backfill check, %s: %d accounts, %d with no recorded creation; %d imported',
                $id === 0 ? 'platform' : "property $id",
                $counts['accounts'],
                $counts['unrecorded'],
                $counts['imported'],
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
        return in_array('access_events', $this->connection->getSchemaCollection()->listTables(), true);
    }
}
