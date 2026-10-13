<?php
declare(strict_types=1);

namespace App\Auth;

use Cake\ORM\TableRegistry;

/**
 * Who may manage whom (Permissions Phase 3a, approved 2026-10-12 as PH-D3):
 * decided by permissions, never by role codes, so a role the code doesn't
 * know is handled like any other.
 *
 * You manage someone only if their role's permissions are a strict subset of
 * your own role's: a Manager (45) manages Front Desk Staff (15, all within
 * the Manager's), never another Manager (the same set). The Platform Owner
 * (platform flag) manages every property's staff. A role is compared by its
 * grants as defined, not as a lapsed subscription narrows them (A7), so
 * enforcement never reshuffles who manages whom.
 */
final class StaffHierarchy
{
    /**
     * The kinds of role a person can be given: the built-ins and, since Phase
     * 3c-2, the Platform Owner's custom roles. Archived roles are never offered.
     */
    public const ASSIGNABLE_KINDS = ['system', 'custom'];

    /**
     * Whether the actor may manage someone holding `$targetRoleId`.
     */
    public static function manages(Access $actor, ?int $targetRoleId): bool
    {
        if ($actor->platform && $actor->supportSession === null) {
            return true;
        }
        $mine = self::actorRoleId($actor);
        if ($mine === null || $targetRoleId === null) {
            return false;
        }
        $own = self::grants($mine);
        $theirs = self::grants($targetRoleId);

        return array_diff($theirs, $own) === [] && count($theirs) < count($own);
    }

    /**
     * Whether the actor may give someone `$role` (Phase 3c-2, C-D1): a custom
     * role only the Platform Owner, outside a support session; a built-in
     * whoever manages its holders (the strict-subset rule). Assignment never
     * exceeds the assigner's own permissions (PH-D4).
     *
     * @param array{id: int, kind: string} $role A property role.
     */
    public static function mayAssign(Access $actor, array $role): bool
    {
        if ($role['kind'] === 'custom') {
            return $actor->platform && $actor->supportSession === null;
        }

        return self::manages($actor, $role['id']);
    }

    /**
     * The property roles the actor may give someone: every one for the
     * Platform Owner, else the built-ins below their own.
     *
     * @return list<array{id: int, code: string, name: string, kind: string, description: string|null}>
     */
    public static function assignable(Access $actor): array
    {
        return array_values(array_filter(self::propertyRoles(), fn($r) => self::mayAssign($actor, $r)));
    }

    /**
     * The roles a person can be given at a property: built-ins first, then
     * custom roles, each oldest first.
     *
     * @return list<array{id: int, code: string, name: string, kind: string, description: string|null}>
     */
    public static function propertyRoles(): array
    {
        $rows = TableRegistry::getTableLocator()->get('Roles')->find()
            ->select(['id', 'code', 'name', 'kind', 'description'])
            ->where(['scope' => 'property', 'kind IN' => self::ASSIGNABLE_KINDS, 'archived_at IS' => null])
            ->orderBy(['id' => 'ASC'])->disableHydration()->all()->toList();
        $roles = array_map(fn($r) => [
            'id' => (int)$r['id'],
            'code' => $r['code'],
            'name' => $r['name'],
            'kind' => $r['kind'],
            'description' => $r['description'],
        ], $rows);
        usort($roles, fn($a, $b) => [$a['kind'] !== 'system', $a['id']] <=> [$b['kind'] !== 'system', $b['id']]);

        return $roles;
    }

    /**
     * A property role a person can be given, by its id or (for one release,
     * C2-D1) its code; null when there is none or it is archived.
     *
     * @return array{id: int, code: string, name: string, kind: string, description: string|null}|null
     */
    public static function propertyRole(int|string $idOrCode): ?array
    {
        foreach (self::propertyRoles() as $role) {
            if (is_int($idOrCode) ? $role['id'] === $idOrCode : $role['code'] === $idOrCode) {
                return $role;
            }
        }

        return null;
    }

    /**
     * Display names for role codes, from the roles themselves (archived and
     * custom ones included): code => name. A code no role has (the Platform
     * Owner's `owner`) is left out; screens label it themselves.
     *
     * @param list<string|null> $codes Role codes, e.g. events' `actor_role`.
     * @return array<string, string>
     */
    public static function namesOf(array $codes): array
    {
        $codes = array_values(array_unique(array_filter($codes, fn($c) => is_string($c) && $c !== '')));
        if ($codes === []) {
            return [];
        }

        return TableRegistry::getTableLocator()->get('Roles')->find()
            ->select(['code', 'name'])->where(['code IN' => $codes])
            ->disableHydration()->all()->combine('code', 'name')->toArray();
    }

    /**
     * Each person's role from their active membership (the oldest, as
     * AccessResolver uses), optionally at one property: user id => role.
     *
     * @param list<int> $userIds The people.
     * @param int|null $propertyId Only memberships at this property.
     * @return array<int, array{id: int, code: string, name: string, property_id: int}>
     */
    public static function rolesOf(array $userIds, ?int $propertyId = null): array
    {
        if ($userIds === []) {
            return [];
        }
        $query = TableRegistry::getTableLocator()->get('PropertyMemberships')->find('active')
            ->select([
                'user_id' => 'PropertyMemberships.user_id',
                'property_id' => 'PropertyMemberships.property_id',
                'id' => 'Roles.id', 'code' => 'Roles.code', 'name' => 'Roles.name',
            ])
            ->innerJoinWith('Roles')
            ->where(['PropertyMemberships.user_id IN' => $userIds])
            ->orderBy(['PropertyMemberships.id' => 'ASC'])
            ->disableHydration();
        if ($propertyId !== null) {
            $query->where(['PropertyMemberships.property_id' => $propertyId]);
        }
        $roles = [];
        foreach ($query->all() as $row) {
            $roles[(int)$row['user_id']] ??= [
                'id' => (int)$row['id'],
                'code' => $row['code'],
                'name' => $row['name'],
                'property_id' => (int)$row['property_id'],
            ];
        }

        return $roles;
    }

    /**
     * The permissions a role holds as defined.
     *
     * @return list<string>
     */
    public static function grants(int $roleId): array
    {
        return array_values(array_unique(TableRegistry::getTableLocator()->get('RolePermissions')->find()
            ->select(['permission'])->where(['role_id' => $roleId])
            ->disableHydration()->all()->extract('permission')->toList()));
    }

    /**
     * The actor's own role, from their membership.
     */
    private static function actorRoleId(Access $actor): ?int
    {
        if ($actor->membershipId === null) {
            return null;
        }
        $row = TableRegistry::getTableLocator()->get('PropertyMemberships')->find()
            ->select(['role_id'])->where(['id' => $actor->membershipId])->disableHydration()->first();

        return $row === null ? null : (int)$row['role_id'];
    }
}
