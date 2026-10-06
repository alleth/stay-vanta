<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Auth\Permissions;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;

/**
 * Which permission each role holds (Permissions Phase 2). A name the code
 * catalog doesn't define is refused: the code is what enforces a permission,
 * so a grant of an unknown one would mean nothing.
 */
class RolePermissionsTable extends Table
{
    /**
     * @param array<string, mixed> $config Table config.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('role_permissions');
        $this->addBehavior('Timestamp');
        $this->belongsTo('Roles');
    }

    /**
     * @param \Cake\ORM\RulesChecker $rules The rules.
     * @return \Cake\ORM\RulesChecker
     */
    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add(
            fn($entity) => in_array($entity->get('permission'), Permissions::ALL, true),
            'knownPermission',
            ['errorField' => 'permission', 'message' => 'Not a permission the platform defines.'],
        );

        return $rules;
    }
}
