<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Auth\Permissions;

/**
 * Role definitions (Permissions Phase 2, build step 10b): the presets and the
 * permissions each holds, read-only until Phase 3 makes them editable (and
 * audited). Shown in Settings → Roles.
 */
class RolesController extends AppController
{
    /**
     * GET /api/roles → { roles: [{ code, name, permissions }] }
     */
    public function index(): void
    {
        $this->request->allowMethod('get');
        $this->authorize(Permissions::SETTINGS_ROLE_VIEW, 'Only Managers can see role definitions.');

        $roles = $this->fetchTable('Roles')->find()
            ->contain(['RolePermissions' => fn($q) => $q->select(['role_id', 'permission'])])
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
}
