<?php
declare(strict_types=1);

namespace App\Event;

use App\Auth\Permissions;
use App\Model\Table\AccessEventsTable;
use App\Model\Table\RolesTable;
use Cake\Database\Connection;
use Cake\I18n\DateTime;
use Cake\Log\Log;
use Cake\ORM\TableRegistry;

/**
 * Permissions Phase 2 from today's accounts (build step 10b).
 *
 * - **Roles:** the two presets (`admin` Manager, `receptionist` Front Desk
 *   Staff) and their grants, from `Permissions::ROLE_GRANTS`. Only what's
 *   missing is added; a grant is never removed here. The presets are code
 *   shipped by a migration, not something a person did, so seeding them
 *   records no event (git and this migration are their history; Phase 3
 *   makes roles editable, and audited).
 * - **Memberships:** one per Manager or Front Desk account with an existing
 *   property and no membership yet, from `users.role` / `users.property_id`.
 *   `started_at` is the account's own `created` (a property can't be changed
 *   today, so that's when the person got it); the `membership_imported`
 *   event is dated when the import runs, with no actor and no reason.
 *   Inactive accounts get one too: the account is switched off, the
 *   membership isn't ended (nobody ended it).
 * - **Platform flag:** set for each `owner` account, recorded as
 *   `platform_access_granted` (platform scope, no actor).
 *
 * Follows the backfill rule (CLAUDE.md): idempotent (an account with a
 * membership, or an owner whose grant is recorded, is skipped); auditable
 * (`source = 'import'`, correlation `import-users-<id>`, a logged check);
 * repeatable (`bin/cake activity_backfill`, batched, each batch its own
 * transaction). An account naming a property that doesn't exist is reported
 * and left out.
 */
final class MembershipImport
{
    private const BATCH = 200;

    /**
     * @param \Cake\Database\Connection $connection The application database.
     */
    public function __construct(private Connection $connection)
    {
    }

    /**
     * Add the role presets and any grant they're missing.
     *
     * @return array{roles: int, grants: int} What was added.
     */
    public function seedRoles(): array
    {
        $result = ['roles' => 0, 'grants' => 0];
        if (!$this->hasTables()) {
            return $result;
        }
        $locator = TableRegistry::getTableLocator();
        /** @var \App\Model\Table\RolesTable $roles */
        $roles = $locator->get('Roles');
        $grants = $locator->get('RolePermissions');
        $this->connection->transactional(function () use ($roles, $grants, &$result): void {
            foreach (RolesTable::PRESETS as $code => $name) {
                $roleId = $roles->idFor($code);
                if ($roleId === null) {
                    $role = $roles->saveOrFail($roles->newEntity(
                        ['code' => $code, 'name' => $name, 'scope' => 'property', 'is_preset' => true],
                        ['accessibleFields' => ['*' => true]],
                    ), ['atomic' => false]);
                    $roleId = (int)$role->id;
                    $result['roles']++;
                }
                $held = $grants->find()->select(['permission'])->where(['role_id' => $roleId])
                    ->disableHydration()->all()->extract('permission')->toList();
                foreach (array_diff(Permissions::ROLE_GRANTS[$code], $held) as $permission) {
                    $grants->saveOrFail($grants->newEntity(
                        ['role_id' => $roleId, 'permission' => $permission],
                        ['accessibleFields' => ['*' => true]],
                    ), ['atomic' => false]);
                    $result['grants']++;
                }
            }
        });

        return $result;
    }

    /**
     * @param int|null $propertyId Limit memberships to one property's accounts (the platform flag is
     *   imported only on a full run).
     * @return array{memberships: int, platform: int, skipped: int} What was added, and what couldn't be.
     */
    public function run(?int $propertyId = null): array
    {
        $result = ['memberships' => 0, 'platform' => 0, 'skipped' => 0];
        if (!$this->hasTables()) {
            return $result;
        }
        $this->seedRoles();

        $locator = TableRegistry::getTableLocator();
        $users = $locator->get('Users');
        /** @var \App\Model\Table\RolesTable $roles */
        $roles = $locator->get('Roles');
        $memberships = $locator->get('PropertyMemberships');
        /** @var \App\Model\Table\AccessEventsTable $events */
        $events = $locator->get('AccessEvents');
        $roleIds = [];
        foreach (array_keys(RolesTable::PRESETS) as $code) {
            $roleIds[$code] = $roles->idFor($code);
        }
        $known = array_flip(array_map('intval', array_column(
            $this->connection->execute('SELECT id FROM properties')->fetchAll('assoc'),
            'id',
        )));
        $now = DateTime::now();

        $query = $users->find()
            ->where(['Users.role IN' => array_keys(RolesTable::PRESETS)])
            ->where(function ($exp, $q) {
                return $exp->notExists(
                    $q->getConnection()->selectQuery('1', 'property_memberships')
                        ->where(['property_memberships.user_id = Users.id']),
                );
            })
            ->orderBy(['Users.id' => 'ASC']);
        if ($propertyId !== null) {
            $query->where(['Users.property_id' => $propertyId]);
        }

        $skipped = [];
        foreach (array_chunk($query->all()->toList(), self::BATCH) as $batch) {
            $this->connection->transactional(function () use (
                $batch,
                $memberships,
                $events,
                $roleIds,
                $known,
                $now,
                &$result,
                &$skipped,
            ): void {
                foreach ($batch as $user) {
                    $property = $user->get('property_id') !== null ? (int)$user->get('property_id') : null;
                    $roleId = $roleIds[$user->get('role')] ?? null;
                    if ($property === null || !isset($known[$property]) || $roleId === null) {
                        $skipped[] = (int)$user->get('id');
                        continue;
                    }
                    $membership = $memberships->saveOrFail($memberships->newEntity([
                        'user_id' => (int)$user->get('id'),
                        'property_id' => $property,
                        'role_id' => $roleId,
                        'started_at' => $user->get('created') ?? $now,
                    ], ['accessibleFields' => ['*' => true]]), ['atomic' => false]);
                    $context = $this->importContext($property, (int)$user->get('id'), $now);
                    $events->record($context, AccessEventsTable::MEMBERSHIP_IMPORTED, $user, [
                        'changes' => ['after' => ['property_id' => $property, 'role' => $user->get('role')]],
                        'columns' => ['membership_id' => (int)$membership->id, 'role_id' => $roleId],
                        'snapshot' => [
                            'active_at_import' => (bool)$user->get('is_active'),
                            'started_from' => $user->get('created') !== null ? 'account_created' : 'import',
                        ],
                    ]);
                    $result['memberships']++;
                }
            });
        }

        if ($propertyId === null) {
            $owners = $users->find()
                ->where(['Users.role' => 'owner', 'Users.property_id IS' => null])
                ->where(function ($exp, $q) {
                    return $exp->notExists(
                        $q->getConnection()->selectQuery('1', 'access_events')
                            ->where([
                                'access_events.subject_user_id = Users.id',
                                'access_events.event_type' => AccessEventsTable::PLATFORM_ACCESS_GRANTED,
                            ]),
                    );
                })
                ->orderBy(['Users.id' => 'ASC'])
                ->all()->toList();
            $this->connection->transactional(function () use ($owners, $users, $events, $now, &$result): void {
                foreach ($owners as $owner) {
                    $owner->set('is_platform', true);
                    $users->saveOrFail($owner, ['atomic' => false]);
                    $context = $this->importContext(null, (int)$owner->get('id'), $now);
                    $events->record($context, AccessEventsTable::PLATFORM_ACCESS_GRANTED, $owner, [
                        'snapshot' => ['active_at_import' => (bool)$owner->get('is_active')],
                    ]);
                    $result['platform']++;
                }
            });
        }
        $result['skipped'] = count($skipped);

        Log::info(sprintf(
            'membership import run%s: %d memberships, %d platform flags imported',
            $propertyId !== null ? " (property $propertyId)" : '',
            $result['memberships'],
            $result['platform'],
        ));
        if ($skipped !== []) {
            Log::warning(sprintf(
                'membership import: %d account(s) not imported (no existing property or unknown role): ids %s',
                count($skipped),
                implode(', ', $skipped),
            ));
        }

        return $result;
    }

    /**
     * Per property (and the platform, key 0): staff accounts, those with no
     * membership (must be zero, or reported), active memberships and imported
     * ones; for the platform, owner accounts, flagged ones and recorded grants.
     * Logged: `info` when complete, `warning` otherwise.
     *
     * @param int|null $propertyId Limit to one property.
     * @return array<int, array<string, int|bool>>
     */
    public function check(?int $propertyId = null): array
    {
        if (!$this->hasTables()) {
            return [];
        }
        $where = $propertyId !== null ? 'WHERE p.id = ' . (int)$propertyId : '';
        $staff = "u.property_id = p.id AND u.role IN ('admin', 'receptionist')";
        $rows = $this->connection->execute(
            "SELECT p.id,
                (SELECT COUNT(*) FROM users u WHERE $staff) AS accounts,
                (SELECT COUNT(*) FROM users u WHERE $staff AND NOT EXISTS
                    (SELECT 1 FROM property_memberships m WHERE m.user_id = u.id)) AS without_membership,
                (SELECT COUNT(*) FROM property_memberships m WHERE m.property_id = p.id
                    AND m.ended_at IS NULL) AS active_memberships,
                (SELECT COUNT(*) FROM access_events e WHERE e.property_id = p.id
                    AND e.event_type = 'membership_imported' AND e.source = 'import') AS imported
            FROM properties p $where ORDER BY p.id",
        )->fetchAll('assoc');

        $result = [];
        foreach ($rows as $row) {
            $counts = array_map('intval', $row);
            $id = $counts['id'];
            unset($counts['id']);
            $counts['complete'] = $counts['without_membership'] === 0;
            $result[$id] = $counts;
            $line = sprintf(
                'membership import check, property %d: %d staff accounts, %d with no membership; '
                . '%d active memberships, %d imported',
                $id,
                $counts['accounts'],
                $counts['without_membership'],
                $counts['active_memberships'],
                $counts['imported'],
            );
            $counts['complete'] ? Log::info($line) : Log::warning($line);
        }

        if ($propertyId === null) {
            $platform = array_map('intval', $this->connection->execute(
                "SELECT
                    (SELECT COUNT(*) FROM users u WHERE u.role = 'owner' AND u.property_id IS NULL) AS owners,
                    (SELECT COUNT(*) FROM users u WHERE u.role = 'owner' AND u.property_id IS NULL
                        AND u.is_platform = 1) AS flagged,
                    (SELECT COUNT(*) FROM access_events e WHERE e.event_type = 'platform_access_granted')
                        AS granted",
            )->fetchAll('assoc')[0]);
            $platform['complete'] = $platform['owners'] === $platform['flagged'];
            $result[0] = $platform;
            $line = sprintf(
                'membership import check, platform: %d owner accounts, %d flagged, %d grants recorded',
                $platform['owners'],
                $platform['flagged'],
                $platform['granted'],
            );
            $platform['complete'] ? Log::info($line) : Log::warning($line);
        }

        return $result;
    }

    /**
     * An import's context: no actor, no reason, the account's own correlation.
     */
    private function importContext(?int $propertyId, int $userId, DateTime $now): EventContext
    {
        return new EventContext(
            null,
            null,
            $propertyId,
            'import-users-' . $userId,
            EventContext::SOURCE_IMPORT,
            null,
            $now,
        );
    }

    /**
     * Whether the tables exist yet (a fresh database migrates in order).
     */
    private function hasTables(): bool
    {
        $tables = $this->connection->getSchemaCollection()->listTables();

        return in_array('property_memberships', $tables, true) && in_array('access_events', $tables, true);
    }
}
