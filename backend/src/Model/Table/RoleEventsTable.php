<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\Datasource\EntityInterface;
use Cake\ORM\Table;

/**
 * Role-definition ledger (final review G7, approved 2026-10-12 as
 * R-D1–R-D6): every change to what a role may do. Written only by
 * App\Auth\RoleDefinitions, called from migrations: a change is a deploy, so
 * it has no actor (`system`), and its reason names the approval it
 * implements; the request id is the migration's name and the snapshot keeps
 * the release commit and how many active memberships held the role. History
 * before G7 is imported with no actor or reason, dated by each row's own
 * `created` time. Platform events, read by Managers and the Platform Owner;
 * never in a property's Activity. Kept indefinitely.
 */
class RoleEventsTable extends Table
{
    use AppendOnlyTableTrait;
    use EventLedgerTableTrait;

    /** A role preset was created. Needs a reason. */
    public const ROLE_CREATED = 'role_created';
    /** A role's display name changed. Needs a reason. */
    public const ROLE_RENAMED = 'role_renamed';
    /** A permission was added to a role. Needs a reason. */
    public const PERMISSION_GRANTED = 'permission_granted';
    /** A permission was removed from a role (a migration, or its rollback). Needs a reason. */
    public const PERMISSION_REVOKED = 'permission_revoked';
    /** Import: the role as it stood when history began; no actor or reason. */
    public const ROLE_IMPORTED = 'role_imported';
    /** Import: a grant that existed when history began, dated by its row's `created`; no actor or reason. */
    public const PERMISSION_IMPORTED = 'permission_imported';

    public const TYPES = [
        self::ROLE_CREATED,
        self::ROLE_RENAMED,
        self::PERMISSION_GRANTED,
        self::PERMISSION_REVOKED,
        self::ROLE_IMPORTED,
        self::PERMISSION_IMPORTED,
    ];

    public const REQUIRES_REASON = [
        self::ROLE_CREATED,
        self::ROLE_RENAMED,
        self::PERMISSION_GRANTED,
        self::PERMISSION_REVOKED,
    ];

    public const REASON_GRACE = [];

    /**
     * @param array<string, mixed> $config Table config.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('role_events');
        $this->addBehavior('EventLedger', [
            'subjectKey' => 'role_id',
            'subjectType' => 'role',
            'platformEvents' => true,
        ]);
    }

    /**
     * The role as it stood: code, name and scope.
     *
     * @param \Cake\Datasource\EntityInterface $role A role.
     * @return array<string, mixed>
     */
    public function snapshotOf(EntityInterface $role): array
    {
        return ['code' => $role->get('code'), 'name' => $role->get('name'), 'scope' => $role->get('scope')];
    }
}
