<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Auth\Permissions;
use App\Auth\StaffHierarchy;

/**
 * Role definitions (Permissions Phase 2, build step 10b): the presets and the
 * permissions each holds, read-only until Phase 3 makes them editable (and
 * audited). Shown in Settings → Roles, with each change's history (G7).
 */
class RolesController extends AppController
{
    /**
     * GET /api/roles → { roles: [{ code, name, description, kind, preset, permissions }] }
     *
     * The property roles, built-in and custom (E3b-D5: Managers see custom
     * roles read-only), without archived ones; not the history-only
     * `platform` entry (G7).
     */
    public function index(): void
    {
        $this->request->allowMethod('get');
        $this->authorize(Permissions::SETTINGS_ROLE_VIEW, 'Only Managers can see role definitions.');

        $roles = $this->fetchTable('Roles')->find()
            ->contain(['RolePermissions' => fn($q) => $q->select(['role_id', 'permission'])])
            ->where(['Roles.scope' => 'property', 'Roles.archived_at IS' => null])
            ->orderBy(['Roles.kind' => 'DESC', 'Roles.id' => 'ASC'])
            ->all()
            ->map(function ($role) {
                $permissions = array_map(fn($g) => $g->get('permission'), $role->get('role_permissions') ?? []);
                sort($permissions);

                return [
                    'code' => $role->get('code'),
                    'name' => $role->get('name'),
                    'description' => $role->get('description'),
                    'kind' => $role->get('kind'),
                    'preset' => (bool)$role->get('is_preset'),
                    'permissions' => $permissions,
                ];
            })
            ->toList();

        $this->set('roles', $roles);
        $this->viewBuilder()->setOption('serialize', ['roles']);
    }

    /**
     * GET /api/roles/history[?page=] (Managers, `settings.role.view`)
     *
     * Every change to a role definition (G7, R-D4), newest first.
     */
    public function history(): void
    {
        $this->request->allowMethod('get');
        $this->authorize(Permissions::SETTINGS_ROLE_VIEW, 'Only Managers can see role history.');
        $this->respondWithHistory();
    }

    /**
     * GET /api/platform/role-history[?page=] (Platform Owner, `platform.dashboard.view`)
     *
     * The same history from the platform side (G7, R-D4).
     */
    public function platformHistory(): void
    {
        $this->request->allowMethod('get');
        $this->authorize(Permissions::PLATFORM_DASHBOARD_VIEW, 'Only the Platform Owner can see this.');
        $this->respondWithHistory();
    }

    /**
     * Role events, newest first, 50 a page: what, when, why, which release,
     * and how many memberships held the role.
     */
    private function respondWithHistory(): void
    {
        $page = max(1, (int)($this->request->getQuery('page') ?? 1));
        $limit = 50;
        $query = $this->fetchTable('RoleEvents')->find()
            ->orderBy(['RoleEvents.id' => 'DESC'])
            ->limit($limit + 1)
            ->offset(($page - 1) * $limit);
        // One role's history (the role editor, Phase 3b).
        $roleId = $this->request->getQuery('role_id');
        if ($roleId !== null && $roleId !== '') {
            $query->where(['RoleEvents.role_id' => (int)$roleId]);
        }
        $rows = $query->all()->toList();
        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $roleIds = array_values(array_unique(array_map(fn($r) => (int)$r->role_id, $rows)));
        $roles = $roleIds === [] ? [] : $this->fetchTable('Roles')->find()
            ->select(['id', 'code', 'name'])->where(['id IN' => $roleIds])->all()->indexBy('id')->toArray();
        // Who: the Platform Owner for app changes (Phase 3b); releases and imports have no actor.
        $actorIds = array_values(array_unique(array_filter(array_map(fn($r) => $r->actor_id, $rows))));
        $actors = $actorIds === [] ? [] : $this->fetchTable('Users')->find()
            ->select(['id', 'name'])->where(['id IN' => $actorIds])->all()->combine('id', 'name')->toArray();

        // Role names from the roles themselves (3c-2): custom roles included.
        $roleNames = StaffHierarchy::namesOf(array_map(fn($r) => $r->actor_role, $rows));
        $events = array_map(fn($r) => [
            'actor' => $r->actor_id !== null ? ($actors[$r->actor_id] ?? null) : null,
            'actor_role' => $r->actor_role,
            'actor_role_name' => $roleNames[$r->actor_role] ?? null,
            'id' => (int)$r->id,
            'event' => $r->event_type,
            'role' => ($roles[(int)$r->role_id] ?? null)?->get('code'),
            'role_name' => ($roles[(int)$r->role_id] ?? null)?->get('name'),
            'permission' => $r->permission,
            'source' => $r->source,
            'reason' => $r->reason,
            'changes' => $r->changes,
            'commit' => $r->snapshot['commit'] ?? null,
            'active_memberships' => $r->snapshot['active_memberships'] ?? null,
            'request_id' => $r->correlation_id,
            'at' => $r->occurred_at,
        ], $rows);

        $this->set(['events' => $events, 'page' => $page, 'has_more' => $hasMore]);
        $this->viewBuilder()->setOption('serialize', ['events', 'page', 'has_more']);
    }
}
