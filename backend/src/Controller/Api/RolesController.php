<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Auth\Permissions;

/**
 * Role definitions (Permissions Phase 2, build step 10b): the presets and the
 * permissions each holds, read-only until Phase 3 makes them editable (and
 * audited). Shown in Settings → Roles, with each change's history (G7).
 */
class RolesController extends AppController
{
    /**
     * GET /api/roles → { roles: [{ code, name, permissions }] }
     *
     * The property roles only: the history-only `platform` entry (G7) holds
     * no grants a person can be given.
     */
    public function index(): void
    {
        $this->request->allowMethod('get');
        $this->authorize(Permissions::SETTINGS_ROLE_VIEW, 'Only Managers can see role definitions.');

        $roles = $this->fetchTable('Roles')->find()
            ->contain(['RolePermissions' => fn($q) => $q->select(['role_id', 'permission'])])
            ->where(['Roles.scope' => 'property'])
            ->orderBy(['Roles.id' => 'ASC'])
            ->all()
            ->map(function ($role) {
                $permissions = array_map(fn($g) => $g->get('permission'), $role->get('role_permissions') ?? []);
                sort($permissions);

                return [
                    'code' => $role->get('code'),
                    'name' => $role->get('name'),
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
        $rows = $this->fetchTable('RoleEvents')->find()
            ->orderBy(['RoleEvents.id' => 'DESC'])
            ->limit($limit + 1)
            ->offset(($page - 1) * $limit)
            ->all()
            ->toList();
        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $roleIds = array_values(array_unique(array_map(fn($r) => (int)$r->role_id, $rows)));
        $roles = $roleIds === [] ? [] : $this->fetchTable('Roles')->find()
            ->select(['id', 'code', 'name'])->where(['id IN' => $roleIds])->all()->indexBy('id')->toArray();

        $events = array_map(fn($r) => [
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
