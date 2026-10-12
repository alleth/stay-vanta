<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Auth\Permissions;
use App\Auth\RoleCatalog;
use App\Auth\RoleDefinitions;

/**
 * The role catalog (Permissions Phase 3b, approved 2026-10-12 as
 * E3b-D1–D5): the Platform Owner creates custom roles, blank or by copying
 * any role, edits them, reviews permissions new in the catalog, and archives
 * or restores them. Built-ins stay locked (PH-D1). Every change goes through
 * RoleDefinitions into `role_events`, with the Platform Owner as actor, a
 * reason, the request id and the memberships it affects. Reading is
 * `platform.dashboard.view`; changing is `platform.role.manage` (elevated).
 */
class PlatformRolesController extends AppController
{
    /**
     * GET /api/platform/roles → { roles }: every property role with its
     * kind, permission count and who holds it.
     */
    public function index(): void
    {
        $this->request->allowMethod('get');
        $this->authorize(Permissions::PLATFORM_DASHBOARD_VIEW, 'Only the Platform Owner can manage roles.');
        $this->set('roles', RoleCatalog::listing());
        $this->viewBuilder()->setOption('serialize', ['roles']);
    }

    /**
     * GET /api/platform/roles/{id} → { role }: the editor's data (permissions
     * marked against the template, changes since creation, memberships by
     * property, the review list) and its `version`.
     */
    public function view(int $id): void
    {
        $this->request->allowMethod('get');
        $this->authorize(Permissions::PLATFORM_DASHBOARD_VIEW, 'Only the Platform Owner can manage roles.');
        $this->respondWithRole($id);
    }

    /**
     * POST /api/platform/roles {name, description?, copy_from?, permissions?, reason}
     *
     * A custom role, blank (with `permissions`) or a copy of `copy_from`.
     */
    public function add(): void
    {
        $this->request->allowMethod('post');
        $this->authorizeElevated(Permissions::PLATFORM_ROLE_MANAGE, 'Only the Platform Owner can manage roles.');
        $copyFrom = $this->request->getData('copy_from');
        $role = $this->definitions()->createCustom(
            $this->eventContext(),
            (string)$this->request->getData('name'),
            $this->request->getData('description'),
            $copyFrom !== null && $copyFrom !== '' ? (int)$copyFrom : null,
            (array)($this->request->getData('permissions') ?? []),
        );
        $this->response = $this->response->withStatus(201);
        $this->respondWithRole((int)$role->id);
    }

    /**
     * POST /api/platform/roles/{id}/preview {permissions, version} → { preview }
     *
     * The impact of a pending change; writes nothing.
     */
    public function preview(int $id): void
    {
        $this->request->allowMethod('post');
        $this->authorize(Permissions::PLATFORM_ROLE_MANAGE, 'Only the Platform Owner can manage roles.');
        $this->set('preview', RoleCatalog::preview(
            $id,
            (array)($this->request->getData('permissions') ?? []),
            (int)$this->request->getData('version'),
        ));
        $this->viewBuilder()->setOption('serialize', ['preview']);
    }

    /**
     * PATCH /api/platform/roles/{id} {version, name?, description?, permissions?, reason}
     *
     * 409 when the role changed since `version` (E3b-D2).
     */
    public function edit(int $id): void
    {
        $this->request->allowMethod(['patch', 'put']);
        $this->authorizeElevated(Permissions::PLATFORM_ROLE_MANAGE, 'Only the Platform Owner can manage roles.');
        $permissions = $this->request->getData('permissions');
        $this->definitions()->updateCustom(
            $this->eventContext(),
            $id,
            (int)$this->request->getData('version'),
            $this->request->getData('name'),
            $this->request->getData('description'),
            $permissions !== null ? (array)$permissions : null,
        );
        $this->respondWithRole($id);
    }

    /**
     * POST /api/platform/roles/{id}/review {permission, decision: add|decline, reason}
     */
    public function review(int $id): void
    {
        $this->request->allowMethod('post');
        $this->authorizeElevated(Permissions::PLATFORM_ROLE_MANAGE, 'Only the Platform Owner can manage roles.');
        $this->definitions()->review(
            $this->eventContext(),
            $id,
            (string)$this->request->getData('permission'),
            $this->request->getData('decision') === 'add',
        );
        $this->respondWithRole($id);
    }

    /**
     * POST /api/platform/roles/{id}/archive {reason}
     */
    public function archive(int $id): void
    {
        $this->request->allowMethod('post');
        $this->authorizeElevated(Permissions::PLATFORM_ROLE_MANAGE, 'Only the Platform Owner can manage roles.');
        $this->definitions()->archive($this->eventContext(), $id);
        $this->respondWithRole($id);
    }

    /**
     * POST /api/platform/roles/{id}/restore {reason}
     */
    public function restore(int $id): void
    {
        $this->request->allowMethod('post');
        $this->authorizeElevated(Permissions::PLATFORM_ROLE_MANAGE, 'Only the Platform Owner can manage roles.');
        $this->definitions()->restore($this->eventContext(), $id);
        $this->respondWithRole($id);
    }

    /**
     * The editor's data for one role.
     */
    private function respondWithRole(int $id): void
    {
        $this->set('role', RoleCatalog::detail($id));
        $this->viewBuilder()->setOption('serialize', ['role']);
    }

    /**
     * The one writer of role definitions.
     */
    private function definitions(): RoleDefinitions
    {
        /** @var \Cake\Database\Connection $connection */
        $connection = $this->fetchTable('Roles')->getConnection();

        return new RoleDefinitions($connection);
    }
}
