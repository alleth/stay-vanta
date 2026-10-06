<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;

/**
 * Role presets (Permissions Phase 2, build step 10b). `code` is the stored
 * role value (`admin`, `receptionist`) and never changes; `name` is the
 * display name. A role's permissions are its `role_permissions` rows, changed
 * only by migration until Phase 3 makes roles editable (and audited).
 */
class RolesTable extends Table
{
    /** The presets: stored code => display name (ROLE_LABELS on the frontend). */
    public const PRESETS = [
        'admin' => 'Manager',
        'receptionist' => 'Front Desk Staff',
    ];

    /**
     * @param array<string, mixed> $config Table config.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('roles');
        $this->setDisplayField('name');
        $this->addBehavior('Timestamp');
        $this->hasMany('RolePermissions');
    }

    /**
     * A role's id by its code, or null when there's no such role.
     */
    public function idFor(string $code): ?int
    {
        $id = $this->find()->select(['id'])->where(['code' => $code])->disableHydration()->first()['id'] ?? null;

        return $id !== null ? (int)$id : null;
    }
}
