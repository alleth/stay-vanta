<?php
declare(strict_types=1);

namespace App\Auth;

use App\Model\Table\RoleEventsTable;
use Cake\Datasource\EntityInterface;
use Cake\Http\Exception\NotFoundException;
use Cake\ORM\TableRegistry;

/**
 * What the role editor shows (Permissions Phase 3b, approved 2026-10-12 as
 * E3b-D1–D5), computed from data already recorded: memberships, the role's
 * history in `role_events`, the template it was copied from (the
 * `role_copied` event keeps the source's permissions at that moment), what
 * changed since it was created, permissions new in the catalog waiting for a
 * decision (PH-D5), and the impact of a pending change. Read-only: every
 * change goes through RoleDefinitions.
 */
final class RoleCatalog
{
    /**
     * Who holds a role now: active memberships of active accounts, by
     * property (the same count the role's events record). The platform role:
     * active accounts with the platform flag.
     *
     * @return array{total: int, properties: list<array{id: int, name: string, count: int}>}
     */
    public static function memberships(EntityInterface $role): array
    {
        $locator = TableRegistry::getTableLocator();
        if ($role->get('code') === RoleDefinitions::PLATFORM_CODE) {
            $n = $locator->get('Users')->find()->where(['is_platform' => true, 'is_active' => true])->count();

            return ['total' => $n, 'properties' => []];
        }
        $rows = $locator->get('PropertyMemberships')->find('active')
            ->select([
                'pid' => 'PropertyMemberships.property_id',
                'n' => $locator->get('PropertyMemberships')->find()->func()->count('*'),
            ])
            ->innerJoinWith('Users', fn($q) => $q->where(['Users.is_active' => true]))
            ->where(['PropertyMemberships.role_id' => (int)$role->get('id')])
            ->groupBy(['PropertyMemberships.property_id'])
            ->disableHydration()->all()->toList();
        $names = $rows === [] ? [] : $locator->get('Properties')->find()
            ->select(['id', 'name'])->where(['id IN' => array_column($rows, 'pid')])
            ->disableHydration()->all()->combine('id', 'name')->toArray();
        $properties = array_map(fn($r) => [
            'id' => (int)$r['pid'],
            'name' => (string)($names[(int)$r['pid']] ?? 'Property ' . $r['pid']),
            'count' => (int)$r['n'],
        ], $rows);
        usort($properties, fn($a, $b) => [$b['count'], $a['name']] <=> [$a['count'], $b['name']]);

        return ['total' => array_sum(array_column($properties, 'count')), 'properties' => $properties];
    }

    /**
     * A role's version: its latest event id (0 before any), which a save must
     * match (E3b-D2).
     */
    public static function version(int $roleId): int
    {
        $row = TableRegistry::getTableLocator()->get('RoleEvents')->find()
            ->select(['m' => 'MAX(id)'])->where(['role_id' => $roleId])->disableHydration()->first();

        return (int)($row['m'] ?? 0);
    }

    /**
     * Every property role, for the Platform Owner's list.
     *
     * @return list<array<string, mixed>>
     */
    public static function listing(): array
    {
        $roles = TableRegistry::getTableLocator()->get('Roles')->find()
            ->where(['scope' => 'property'])->orderBy(['kind' => 'DESC', 'archived_at' => 'ASC', 'name' => 'ASC'])
            ->all()->toList();
        $names = array_column(array_map(fn($r) => ['id' => (int)$r->id, 'name' => $r->name], $roles), 'name', 'id');

        return array_map(fn($role) => self::summary($role, $names), $roles);
    }

    /**
     * Everything the editor shows for one role.
     *
     * @return array<string, mixed>
     */
    public static function detail(int $roleId): array
    {
        $role = self::role($roleId);
        $held = StaffHierarchy::grants((int)$role->id);
        $origin = self::origin((int)$role->id);
        $initial = $origin['permissions'];
        $permissions = [];
        foreach (Permissions::ALL as $permission) {
            if (str_starts_with($permission, 'platform.')) {
                continue;
            }
            $isHeld = in_array($permission, $held, true);
            $wasInitial = in_array($permission, $initial, true);
            $permissions[] = [
                'permission' => $permission,
                'module' => strstr($permission, '.', true),
                'elevated' => in_array($permission, Permissions::ELEVATED, true),
                'held' => $isHeld,
                // Against what the role started with: kept from it, added, or removed since.
                'mark' => match (true) {
                    $isHeld && $wasInitial => $origin['template'] !== null ? 'template' : 'initial',
                    $isHeld => 'added',
                    $wasInitial => 'removed',
                    default => null,
                },
            ];
        }
        $names = TableRegistry::getTableLocator()->get('Roles')->find()
            ->select(['id', 'name'])->disableHydration()->all()->combine('id', 'name')->toArray();

        return self::summary($role, $names) + [
            'version' => self::version((int)$role->id),
            'permissions' => $permissions,
            'template' => $origin['template'],
            'since_creation' => [
                'added' => array_values(array_diff($held, $initial)),
                'removed' => array_values(array_diff($initial, $held)),
            ],
            'memberships' => self::memberships($role),
            'review' => $role->get('kind') === 'custom' ? self::pendingReview((int)$role->id) : [],
        ];
    }

    /**
     * The impact of a pending change, before it is saved: permissions added
     * and removed, the modules gained and lost, elevated permissions added,
     * who holds the role, and whether the role changed since `$version`.
     *
     * @param list<string> $permissions The whole pending set.
     * @return array<string, mixed>
     */
    public static function preview(int $roleId, array $permissions, int $version): array
    {
        $role = self::role($roleId);
        $held = StaffHierarchy::grants((int)$role->id);
        $wanted = array_values(array_unique(array_map('strval', $permissions)));
        $added = array_values(array_diff($wanted, $held));
        $removed = array_values(array_diff($held, $wanted));
        $modules = fn(array $list) => array_values(array_unique(array_map(fn($p) => strstr($p, '.', true), $list)));
        $current = self::version((int)$role->id);

        return [
            'added' => $added,
            'removed' => $removed,
            // A module is lost when no permission of it remains, gained when none was held.
            'modules_lost' => array_values(array_diff($modules($held), $modules($wanted))),
            'modules_gained' => array_values(array_diff($modules($wanted), $modules($held))),
            'elevated_added' => array_values(array_intersect($added, Permissions::ELEVATED)),
            'memberships' => self::memberships($role),
            'version' => $current,
            'stale' => $current !== $version,
        ];
    }

    /**
     * Permissions new in the catalog since a custom role was created: granted
     * to a built-in after it, not held by the role, and not decided for it
     * since (granted, declined or revoked). PH-D5: never added automatically.
     *
     * @return list<array{permission: string, granted_to: list<string>, at: mixed}>
     */
    public static function pendingReview(int $roleId): array
    {
        $locator = TableRegistry::getTableLocator();
        $events = $locator->get('RoleEvents');
        $since = (int)($events->find()->select(['m' => 'MIN(id)'])->where(['role_id' => $roleId])
            ->disableHydration()->first()['m'] ?? 0);
        $systemIds = $locator->get('Roles')->find()->select(['id'])
            ->where(['kind' => 'system', 'scope' => 'property'])->disableHydration()->all()->extract('id')->toList();
        if ($since === 0 || $systemIds === []) {
            return [];
        }
        $held = StaffHierarchy::grants($roleId);
        $names = $locator->get('Roles')->find()->select(['id', 'name'])->disableHydration()->all()
            ->combine('id', 'name')->toArray();
        $pending = [];
        $grants = $events->find()
            ->where([
                'role_id IN' => $systemIds,
                'event_type' => RoleEventsTable::PERMISSION_GRANTED,
                'id >' => $since,
            ])->orderBy(['id' => 'ASC'])->all();
        foreach ($grants as $grant) {
            $permission = (string)$grant->permission;
            if (in_array($permission, $held, true) || str_starts_with($permission, 'platform.')) {
                continue;
            }
            $decided = $events->exists([
                'role_id' => $roleId,
                'permission' => $permission,
                'id >' => (int)$grant->id,
                'event_type IN' => [
                    RoleEventsTable::PERMISSION_GRANTED,
                    RoleEventsTable::PERMISSION_DECLINED,
                    RoleEventsTable::PERMISSION_REVOKED,
                ],
            ]);
            if ($decided) {
                continue;
            }
            $pending[$permission] ??= ['permission' => $permission, 'granted_to' => [], 'at' => $grant->occurred_at];
            $pending[$permission]['granted_to'][] = (string)($names[(int)$grant->role_id] ?? '');
            $pending[$permission]['at'] = $grant->occurred_at;
        }

        return array_values($pending);
    }

    /**
     * What a role started with: the permissions granted in the request that
     * created it, and, for a copy, its source and the source's permissions
     * then. A role imported at G7 started with its imported grants.
     *
     * @return array{permissions: list<string>, template: array<string, mixed>|null}
     */
    private static function origin(int $roleId): array
    {
        $events = TableRegistry::getTableLocator()->get('RoleEvents');
        $first = $events->find()->where(['role_id' => $roleId])->orderBy(['id' => 'ASC'])->first();
        if ($first === null) {
            return ['permissions' => StaffHierarchy::grants($roleId), 'template' => null];
        }
        $initial = $events->find()
            ->select(['permission'])
            ->where([
                'role_id' => $roleId,
                'correlation_id' => $first->correlation_id,
                'event_type IN' => [RoleEventsTable::PERMISSION_GRANTED, RoleEventsTable::PERMISSION_IMPORTED],
            ])->disableHydration()->all()->extract('permission')->toList();
        $template = null;
        if ($first->event_type === RoleEventsTable::ROLE_COPIED) {
            $template = [
                'source' => $first->changes['source'] ?? null,
                'permissions' => $first->changes['template'] ?? [],
                'copied_at' => $first->occurred_at,
            ];
        }

        return ['permissions' => array_values(array_unique($initial)), 'template' => $template];
    }

    /**
     * A role's list row.
     *
     * @param array<int, string> $names Role names by id.
     * @return array<string, mixed>
     */
    private static function summary(EntityInterface $role, array $names): array
    {
        $memberships = self::memberships($role);
        $sourceId = $role->get('copied_from_role_id');

        return [
            'id' => (int)$role->id,
            'code' => $role->code,
            'name' => $role->name,
            'description' => $role->get('description'),
            'kind' => $role->get('kind'),
            'archived_at' => $role->get('archived_at'),
            'copied_from' => $sourceId !== null
                ? ['id' => (int)$sourceId, 'name' => $names[(int)$sourceId] ?? null]
                : null,
            'permission_count' => count(StaffHierarchy::grants((int)$role->id)),
            'memberships' => $memberships,
        ];
    }

    /**
     * A property role, or 404.
     */
    private static function role(int $roleId): EntityInterface
    {
        $role = TableRegistry::getTableLocator()->get('Roles')->find()
            ->where(['id' => $roleId, 'scope' => 'property'])->first();
        if ($role === null) {
            throw new NotFoundException('No such role.');
        }

        return $role;
    }
}
