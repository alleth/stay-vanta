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
     * The kinds of role a person can be given. Custom roles (Phase 3b) are
     * defined and reviewed first; giving them to people is Phase 3c, which
     * adds `custom` here. Archived roles are never offered.
     */
    public const ASSIGNABLE_KINDS = ['system'];

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
     * The property roles the actor may give someone: every one for the
     * Platform Owner, else those below their own.
     *
     * @return list<array{id: int, code: string, name: string}>
     */
    public static function assignable(Access $actor): array
    {
        return array_values(array_filter(self::propertyRoles(), fn($r) => self::manages($actor, $r['id'])));
    }

    /**
     * The roles a person can be given at a property, oldest first.
     *
     * @return list<array{id: int, code: string, name: string}>
     */
    public static function propertyRoles(): array
    {
        $rows = TableRegistry::getTableLocator()->get('Roles')->find()
            ->select(['id', 'code', 'name'])
            ->where(['scope' => 'property', 'kind IN' => self::ASSIGNABLE_KINDS, 'archived_at IS' => null])
            ->orderBy(['id' => 'ASC'])->disableHydration()->all()->toList();

        return array_map(fn($r) => ['id' => (int)$r['id'], 'code' => $r['code'], 'name' => $r['name']], $rows);
    }

    /**
     * A property role by its code, or null.
     *
     * @return array{id: int, code: string, name: string}|null
     */
    public static function propertyRole(string $code): ?array
    {
        foreach (self::propertyRoles() as $role) {
            if ($role['code'] === $code) {
                return $role;
            }
        }

        return null;
    }

    /**
     * Each person's role from their active membership (the oldest, as
     * AccessResolver uses), optionally at one property: user id => role.
     *
     * @param list<int> $userIds The people.
     * @param int|null $propertyId Only memberships at this property.
     * @return array<int, array{id: int, code: string, name: string}>
     */
    public static function rolesOf(array $userIds, ?int $propertyId = null): array
    {
        if ($userIds === []) {
            return [];
        }
        $query = TableRegistry::getTableLocator()->get('PropertyMemberships')->find('active')
            ->select([
                'user_id' => 'PropertyMemberships.user_id',
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
            $roles[(int)$row['user_id']] ??= ['id' => (int)$row['id'], 'code' => $row['code'], 'name' => $row['name']];
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
