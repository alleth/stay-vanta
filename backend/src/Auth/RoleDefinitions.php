<?php
declare(strict_types=1);

namespace App\Auth;

use App\Event\EventContext;
use App\Model\Table\RoleEventsTable;
use App\Model\Table\RolePermissionsTable;
use App\Model\Table\RolesTable;
use Cake\Database\Connection;
use Cake\Datasource\EntityInterface;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\ConflictException;
use Cake\Http\Exception\NotFoundException;
use Cake\I18n\DateTime;
use Cake\Log\Log;
use Cake\ORM\TableRegistry;
use InvalidArgumentException;

/**
 * The one way a role definition changes (final review G7, approved
 * 2026-10-12 as R-D1–R-D6), called from migrations: a role's permissions are
 * a reviewed behaviour change (docs/PERMISSIONS.md first) that ships with a
 * release.
 *
 * - grant(): adds what `Permissions::ROLE_GRANTS` defines and the data lacks.
 * - revoke(): the only way to remove a grant, by name, with a reason (also a
 *   migration's `down()`). Nothing is revoked because code and data differ.
 * - rename(): a role's display name.
 *
 * Each change is recorded in `role_events` in its transaction: no actor (a
 * deploy, source `system`), the reason the migration gives (the approval it
 * implements), the migration as the request id (`migration-<Name>`), and a
 * snapshot with the release commit when the platform provides it and how
 * many active memberships held the role (R-D6). import() records the history
 * before G7 without inventing an actor, a reason or a removal. check() is the
 * read-only drift self-check (R-D2): it warns, it never fixes. The Platform
 * Owner's grants live in code; observePlatform() records their changes on a
 * history-only `platform` role (R-D5).
 *
 * Before `role_events` exists (a fresh database migrating in order) changes
 * are written without events, as before G7; import() then records them.
 */
final class RoleDefinitions
{
    /** The history-only role entry for the Platform Owner's code-defined grants. */
    public const PLATFORM_CODE = 'platform';

    /**
     * @param \Cake\Database\Connection $connection The application database.
     */
    public function __construct(private Connection $connection)
    {
    }

    /**
     * Grant every permission the code defines for a preset and the data lacks
     * (creating a missing preset). Idempotent.
     *
     * @param string $reason Why: the approval this implements.
     * @param string $migration The migration making the change.
     * @return array{roles: int, grants: int} What was added.
     */
    public function grant(string $reason, string $migration): array
    {
        $this->requireReason($reason);
        $result = ['roles' => 0, 'grants' => 0];
        $this->connection->transactional(function () use ($reason, $migration, &$result): void {
            $context = $this->context($reason, $migration);
            foreach (RolesTable::PRESETS as $code => $name) {
                $role = $this->roles()->find()->where(['code' => $code])->epilog('FOR UPDATE')->first();
                if ($role === null) {
                    $role = $this->roles()->newEntity(
                        ['code' => $code, 'name' => $name, 'scope' => 'property', 'is_preset' => true],
                        ['accessibleFields' => ['*' => true]],
                    );
                    $this->roles()->saveOrFail($role, ['roleDefinitions' => true, 'atomic' => false]);
                    $this->record($context, RoleEventsTable::ROLE_CREATED, $role, null, [
                        'role' => ['before' => null, 'after' => $name],
                    ]);
                    $result['roles']++;
                }
                foreach (array_diff(Permissions::ROLE_GRANTS[$code], $this->held((int)$role->id)) as $permission) {
                    $this->grants()->saveOrFail($this->grants()->newEntity(
                        ['role_id' => (int)$role->id, 'permission' => $permission],
                        ['accessibleFields' => ['*' => true]],
                    ), ['roleDefinitions' => true, 'atomic' => false]);
                    $this->record($context, RoleEventsTable::PERMISSION_GRANTED, $role, $permission, [
                        'permission' => ['before' => false, 'after' => true],
                    ]);
                    $result['grants']++;
                }
            }
        });

        return $result;
    }

    /**
     * Remove named permissions from a role, with a reason. Permissions the
     * role doesn't hold are skipped. Takes effect on the next request.
     *
     * @param string $code The role's code.
     * @param list<string> $permissions The permissions to remove.
     * @param string $reason Why (for a rollback: "Rolled back: <migration>").
     * @param string $migration The migration making the change.
     * @return int How many were revoked.
     */
    public function revoke(string $code, array $permissions, string $reason, string $migration): int
    {
        $this->requireReason($reason);
        $revoked = 0;
        $this->connection->transactional(function () use ($code, $permissions, $reason, $migration, &$revoked): void {
            $role = $this->roles()->find()->where(['code' => $code])->epilog('FOR UPDATE')->first();
            if ($role === null) {
                return;
            }
            $context = $this->context($reason, $migration);
            foreach ($permissions as $permission) {
                $row = $this->grants()->find()
                    ->where(['role_id' => (int)$role->id, 'permission' => $permission])->first();
                if ($row === null) {
                    continue;
                }
                $this->grants()->deleteOrFail($row, ['roleDefinitions' => true, 'atomic' => false]);
                $this->record($context, RoleEventsTable::PERMISSION_REVOKED, $role, $permission, [
                    'permission' => ['before' => true, 'after' => false],
                ]);
                $revoked++;
            }
        });

        return $revoked;
    }

    /**
     * Change a role's display name (its code never changes).
     *
     * @param string $code The role's code.
     * @param string $name The new display name.
     * @param string $reason Why.
     * @param string $migration The migration making the change.
     * @return bool Whether it changed.
     */
    public function rename(string $code, string $name, string $reason, string $migration): bool
    {
        $this->requireReason($reason);

        return $this->connection->transactional(function () use ($code, $name, $reason, $migration): bool {
            $role = $this->roles()->find()->where(['code' => $code])->epilog('FOR UPDATE')->first();
            if ($role === null || $role->get('name') === $name) {
                return false;
            }
            $before = $role->get('name');
            $role->set('name', $name);
            $this->roles()->saveOrFail($role, ['roleDefinitions' => true, 'atomic' => false]);
            $this->record($this->context($reason, $migration), RoleEventsTable::ROLE_RENAMED, $role, null, [
                'name' => ['before' => $before, 'after' => $name],
            ]);

            return true;
        });
    }

    /**
     * Record the role definitions as they stood when history began, once:
     * one `role_imported` per role and one `permission_imported` per grant,
     * dated by each row's own `created` time; no actor, no reason, source
     * `import`. Also creates the history-only `platform` role and records the
     * Platform Owner's code grants as observed now (R-D5). Idempotent.
     *
     * @return array{roles: int, grants: int, platform: int} What was imported.
     */
    public function import(): array
    {
        $result = ['roles' => 0, 'grants' => 0, 'platform' => 0];
        if (!$this->ledgerReady()) {
            return $result;
        }
        $this->connection->transactional(function () use (&$result): void {
            foreach ($this->roles()->find()->where(['scope' => 'property'])->all() as $role) {
                if (!$this->events()->exists(['role_id' => (int)$role->id])) {
                    $this->record(
                        $this->importContext('roles', (int)$role->id, $role->get('created')),
                        RoleEventsTable::ROLE_IMPORTED,
                        $role,
                    );
                    $result['roles']++;
                }
                foreach ($this->grants()->find()->where(['role_id' => (int)$role->id])->all() as $grant) {
                    $known = $this->events()->exists([
                        'role_id' => (int)$role->id,
                        'permission' => $grant->get('permission'),
                    ]);
                    if (!$known) {
                        $this->record(
                            $this->importContext('role_permissions', (int)$grant->id, $grant->get('created')),
                            RoleEventsTable::PERMISSION_IMPORTED,
                            $role,
                            (string)$grant->get('permission'),
                        );
                        $result['grants']++;
                    }
                }
            }

            $platform = $this->roles()->find()->where(['code' => self::PLATFORM_CODE])->first();
            if ($platform === null) {
                $platform = $this->roles()->newEntity(
                    [
                        'code' => self::PLATFORM_CODE, 'name' => 'Platform Owner',
                        'scope' => 'platform', 'is_preset' => true,
                    ],
                    ['accessibleFields' => ['*' => true]],
                );
                $this->roles()->saveOrFail($platform, ['roleDefinitions' => true, 'atomic' => false]);
                $context = $this->importContext('roles', (int)$platform->id, null);
                $this->record($context, RoleEventsTable::ROLE_IMPORTED, $platform, null, [
                    'grants_defined_in' => 'code (Permissions::ROLE_GRANTS[owner]); earlier changes are in git history',
                ]);
                foreach (Permissions::ROLE_GRANTS['owner'] as $permission) {
                    $this->record($context, RoleEventsTable::PERMISSION_IMPORTED, $platform, $permission, [
                        'observed_at_release' => true,
                    ]);
                    $result['platform']++;
                }
            }
        });

        return $result;
    }

    /**
     * Record a change to the Platform Owner's code-defined grants since the
     * last recorded set (start-up, R-D5). History only: access still comes
     * from code. Idempotent.
     *
     * @return int How many grants and revocations were recorded.
     */
    public function observePlatform(): int
    {
        if (!$this->ledgerReady()) {
            return 0;
        }

        return $this->connection->transactional(function (): int {
            $platform = $this->roles()->find()->where(['code' => self::PLATFORM_CODE])->epilog('FOR UPDATE')->first();
            if ($platform === null) {
                return 0;
            }
            $recorded = $this->replay((int)$platform->id);
            $code = Permissions::ROLE_GRANTS['owner'];
            $commit = self::commit();
            $context = $this->context(
                sprintf('Platform Owner grants changed in code (release %s)', $commit ?? 'commit not reported'),
                'release-' . ($commit ?? 'unknown'),
            );
            $count = 0;
            foreach (array_diff($code, $recorded) as $permission) {
                $this->record($context, RoleEventsTable::PERMISSION_GRANTED, $platform, $permission, [
                    'permission' => ['before' => false, 'after' => true],
                ]);
                $count++;
            }
            foreach (array_diff($recorded, $code) as $permission) {
                $this->record($context, RoleEventsTable::PERMISSION_REVOKED, $platform, $permission, [
                    'permission' => ['before' => true, 'after' => false],
                ]);
                $count++;
            }

            return $count;
        });
    }

    /**
     * The read-only drift self-check (R-D2): for each preset, the data equals
     * the code in both directions and the history replays to the data; for
     * the platform role, the history replays to the code. Logs `info` when
     * all hold, a `warning` naming each difference otherwise. Never changes
     * anything.
     *
     * @return array<string, mixed>
     */
    public function check(): array
    {
        $result = ['roles' => [], 'warnings' => [], 'ledger' => $this->ledgerReady()];
        foreach (RolesTable::PRESETS as $code => $name) {
            $role = $this->roles()->find()->where(['code' => $code])->first();
            if ($role === null) {
                $result['warnings'][] = "$code: the role is missing";
                continue;
            }
            $data = $this->held((int)$role->id);
            $defined = Permissions::ROLE_GRANTS[$code];
            foreach (array_diff($defined, $data) as $p) {
                $result['warnings'][] = "$code: code grants $p, the data doesn't";
            }
            foreach (array_diff($data, $defined) as $p) {
                $result['warnings'][] = "$code: the data grants $p, the code doesn't";
            }
            if ($result['ledger']) {
                $replayed = $this->replay((int)$role->id);
                sort($replayed);
                sort($data);
                if ($replayed !== $data) {
                    $result['warnings'][] = "$code: the history doesn't replay to the grants held";
                }
            }
            $result['roles'][$code] = count($data);
        }
        if ($result['ledger']) {
            $platform = $this->roles()->find()->where(['code' => self::PLATFORM_CODE])->first();
            if ($platform === null) {
                $result['warnings'][] = 'platform: not imported yet';
            } else {
                $replayed = $this->replay((int)$platform->id);
                $code = Permissions::ROLE_GRANTS['owner'];
                sort($replayed);
                sort($code);
                if ($replayed !== $code) {
                    $result['warnings'][] = "platform: the recorded grants differ from the code's (not observed yet)";
                }
                $result['roles'][self::PLATFORM_CODE] = count($replayed);
            }
            // Custom roles (Phase 3b) have no code to match: their history must
            // replay to the grants they hold.
            $custom = 0;
            foreach ($this->roles()->find()->where(['kind' => 'custom'])->all() as $role) {
                $data = $this->held((int)$role->id);
                $replayed = $this->replay((int)$role->id);
                sort($data);
                sort($replayed);
                if ($replayed !== $data) {
                    $result['warnings'][] = "{$role->code}: the history doesn't replay to the grants held";
                }
                $custom++;
            }
            if ($custom > 0) {
                $result['roles']['custom roles'] = $custom;
            }
            $result['events'] = $this->events()->find()->count();
        }
        $result['complete'] = $result['warnings'] === [];
        $summary = implode(', ', array_map(fn($k, $v) => "$k $v", array_keys($result['roles']), $result['roles']));
        $line = sprintf(
            'roles check: %s grants; %d event(s); %s',
            $summary,
            $result['events'] ?? 0,
            $result['complete'] ? 'data, code and history agree' : 'DRIFT: ' . implode('; ', $result['warnings']),
        );
        $result['complete'] ? Log::info($line) : Log::warning($line);

        return $result;
    }

    /**
     * Create a custom role (Phase 3b, the Platform Owner): blank with the
     * given permissions, or as a copy of `$copyFromId` with that role's
     * permissions at this moment (a copy never follows its source later).
     * Records `role_created` or `role_copied`, then one `permission_granted`
     * per permission, all under the request's id.
     *
     * @param \App\Event\EventContext $context The Platform Owner's request, with its reason.
     * @param list<string> $permissions For a blank role.
     */
    public function createCustom(
        EventContext $context,
        string $name,
        ?string $description,
        ?int $copyFromId,
        array $permissions,
    ): EntityInterface {
        $name = $this->validName($name);

        $work = function () use ($context, $name, $description, $copyFromId, $permissions) {
            $this->requireUniqueName($name, null);
            $source = null;
            if ($copyFromId !== null) {
                $source = $this->roles()->find()
                    ->where(['id' => $copyFromId, 'scope' => 'property'])->epilog('FOR UPDATE')->first();
                if ($source === null) {
                    throw new BadRequestException('Choose a role a person can hold at a property to copy.');
                }
                $permissions = $this->held((int)$source->id);
            }
            $permissions = $this->validPermissions($permissions);
            $role = $this->roles()->newEntity([
                'code' => $this->newCode($name),
                'name' => $name,
                'description' => $this->cleanDescription($description),
                'scope' => 'property',
                'kind' => 'custom',
                'is_preset' => false,
                'copied_from_role_id' => $source?->get('id'),
            ], ['accessibleFields' => ['*' => true]]);
            $this->roles()->saveOrFail($role, ['roleDefinitions' => true, 'atomic' => false]);
            if ($source !== null) {
                $this->record($context, RoleEventsTable::ROLE_COPIED, $role, null, [
                    'source' => ['id' => (int)$source->id, 'code' => $source->code, 'name' => $source->name],
                    'template' => $permissions,
                ]);
            } else {
                $this->record($context, RoleEventsTable::ROLE_CREATED, $role, null, [
                    'role' => ['before' => null, 'after' => $name],
                ]);
            }
            foreach ($permissions as $permission) {
                $this->addGrant($context, $role, $permission);
            }

            return $role;
        };

        return $this->connection->transactional($work);
    }

    /**
     * Change a custom role's name, description and/or permissions (Phase 3b).
     * `$version` is the role's latest event id the editor previewed; a role
     * changed since is refused (E3b-D2), so nobody overwrites what they didn't
     * see. Each difference is one event under the request's id.
     *
     * @param list<string>|null $permissions The whole new set, or null to leave them.
     */
    public function updateCustom(
        EventContext $context,
        int $roleId,
        int $version,
        ?string $name,
        ?string $description,
        ?array $permissions,
    ): EntityInterface {
        $work = function () use ($context, $roleId, $version, $name, $description, $permissions) {
            $role = $this->lockedCustom($roleId);
            if ($role->get('archived_at') !== null) {
                throw new BadRequestException('Restore this role before changing it.');
            }
            $this->requireVersion((int)$role->id, $version);
            $changed = 0;
            if ($name !== null && $this->validName($name) !== $role->get('name')) {
                $name = $this->validName($name);
                $this->requireUniqueName($name, (int)$role->id);
                $before = $role->get('name');
                $role->set('name', $name);
                $this->roles()->saveOrFail($role, ['roleDefinitions' => true, 'atomic' => false]);
                $this->record($context, RoleEventsTable::ROLE_RENAMED, $role, null, [
                    'name' => ['before' => $before, 'after' => $name],
                ]);
                $changed++;
            }
            if ($description !== null && $this->cleanDescription($description) !== $role->get('description')) {
                $before = $role->get('description');
                $role->set('description', $this->cleanDescription($description));
                $this->roles()->saveOrFail($role, ['roleDefinitions' => true, 'atomic' => false]);
                $this->record($context, RoleEventsTable::ROLE_DESCRIPTION_CHANGED, $role, null, [
                    'description' => ['before' => $before, 'after' => $role->get('description')],
                ]);
                $changed++;
            }
            if ($permissions !== null) {
                $wanted = $this->validPermissions($permissions);
                $held = $this->held((int)$role->id);
                foreach (array_diff($wanted, $held) as $permission) {
                    $this->addGrant($context, $role, $permission);
                    $changed++;
                }
                foreach (array_diff($held, $wanted) as $permission) {
                    $this->removeGrant($context, $role, $permission);
                    $changed++;
                }
            }
            if ($changed === 0) {
                throw new BadRequestException('Nothing to change.');
            }

            return $role;
        };

        return $this->connection->transactional($work);
    }

    /**
     * Archive a custom role: only when no active membership holds it (PH-D6).
     */
    public function archive(EventContext $context, int $roleId): EntityInterface
    {
        return $this->connection->transactional(function () use ($context, $roleId) {
            $role = $this->lockedCustom($roleId);
            if ($role->get('archived_at') !== null) {
                throw new BadRequestException('This role is already archived.');
            }
            $held = TableRegistry::getTableLocator()->get('PropertyMemberships')->find()
                ->where(['role_id' => (int)$role->id, 'ended_at IS' => null])->count();
            if ($held > 0) {
                throw new BadRequestException(sprintf(
                    'Reassign the %d %s who hold this role first.',
                    $held,
                    $held === 1 ? 'person' : 'people',
                ));
            }
            $role->set('archived_at', $context->now);
            $this->roles()->saveOrFail($role, ['roleDefinitions' => true, 'atomic' => false]);
            $this->record($context, RoleEventsTable::ROLE_ARCHIVED, $role);

            return $role;
        });
    }

    /**
     * Restore an archived custom role.
     */
    public function restore(EventContext $context, int $roleId): EntityInterface
    {
        return $this->connection->transactional(function () use ($context, $roleId) {
            $role = $this->lockedCustom($roleId);
            if ($role->get('archived_at') === null) {
                throw new BadRequestException('This role isn\'t archived.');
            }
            $role->set('archived_at', null);
            $this->roles()->saveOrFail($role, ['roleDefinitions' => true, 'atomic' => false]);
            $this->record($context, RoleEventsTable::ROLE_RESTORED, $role);

            return $role;
        });
    }

    /**
     * Decide a permission new in the catalog for a custom role (PH-D5,
     * E3b-D3): add it (`permission_granted`) or decline it
     * (`permission_declined`), with a reason. Either clears the review flag.
     */
    public function review(EventContext $context, int $roleId, string $permission, bool $add): EntityInterface
    {
        return $this->connection->transactional(function () use ($context, $roleId, $permission, $add) {
            $role = $this->lockedCustom($roleId);
            $pending = array_column(RoleCatalog::pendingReview((int)$role->id), 'permission');
            if (!in_array($permission, $pending, true)) {
                throw new BadRequestException('This permission isn\'t waiting for a decision on this role.');
            }
            if ($add) {
                $this->addGrant($context, $role, $this->validPermissions([$permission])[0]);
            } else {
                $this->record($context, RoleEventsTable::PERMISSION_DECLINED, $role, $permission, [
                    'permission' => ['before' => false, 'after' => false],
                ]);
            }

            return $role;
        });
    }

    /**
     * The release commit, when the platform reports it.
     */
    public static function commit(): ?string
    {
        $sha = getenv('RAILWAY_GIT_COMMIT_SHA');

        return is_string($sha) && $sha !== '' ? substr($sha, 0, 40) : null;
    }

    /**
     * Record one event, when the ledger exists.
     *
     * @param array<string, mixed> $changes What changed.
     */
    private function record(
        EventContext $context,
        string $type,
        EntityInterface $role,
        ?string $permission = null,
        array $changes = [],
    ): void {
        if (!$this->ledgerReady()) {
            return;
        }
        // An import is dated in the past: today's commit and count would say
        // something that wasn't true then, so it keeps neither. Otherwise the
        // impact (R-D6) is the same count the editor shows: active memberships
        // of active accounts, also by property.
        $snapshot = [];
        if ($context->source !== EventContext::SOURCE_IMPORT) {
            $impact = RoleCatalog::memberships($role);
            $snapshot = [
                'commit' => $context->source === EventContext::SOURCE_SYSTEM ? self::commit() : null,
                'active_memberships' => $impact['total'],
                'memberships_by_property' => array_column($impact['properties'], 'count', 'name'),
            ];
        }
        $this->events()->record($context, $type, $role, [
            'columns' => ['permission' => $permission],
            'changes' => $changes ?: null,
            'snapshot' => $snapshot,
        ]);
    }

    /**
     * Grant one permission to a role and record it.
     */
    private function addGrant(EventContext $context, EntityInterface $role, string $permission): void
    {
        $this->grants()->saveOrFail($this->grants()->newEntity(
            ['role_id' => (int)$role->id, 'permission' => $permission],
            ['accessibleFields' => ['*' => true]],
        ), ['roleDefinitions' => true, 'atomic' => false]);
        $this->record($context, RoleEventsTable::PERMISSION_GRANTED, $role, $permission, [
            'permission' => ['before' => false, 'after' => true],
        ]);
    }

    /**
     * Revoke one permission from a role and record it.
     */
    private function removeGrant(EventContext $context, EntityInterface $role, string $permission): void
    {
        $row = $this->grants()->find()->where(['role_id' => (int)$role->id, 'permission' => $permission])->first();
        if ($row === null) {
            return;
        }
        $this->grants()->deleteOrFail($row, ['roleDefinitions' => true, 'atomic' => false]);
        $this->record($context, RoleEventsTable::PERMISSION_REVOKED, $role, $permission, [
            'permission' => ['before' => true, 'after' => false],
        ]);
    }

    /**
     * A custom role under a FOR UPDATE lock; a system role is refused (PH-D1).
     */
    private function lockedCustom(int $roleId): EntityInterface
    {
        $role = $this->roles()->find()->where(['id' => $roleId, 'scope' => 'property'])->epilog('FOR UPDATE')->first();
        if ($role === null) {
            throw new NotFoundException('No such role.');
        }
        if ($role->get('kind') !== 'custom') {
            throw new BadRequestException(
                'Built-in roles change only with a release. Copy this role to customise it.',
            );
        }

        return $role;
    }

    /**
     * Refuse a change prepared against an older state of the role (E3b-D2).
     */
    private function requireVersion(int $roleId, int $version): void
    {
        if (RoleCatalog::version($roleId) !== $version) {
            throw new ConflictException(
                'This role changed since you opened it. Reload it to see the latest before saving.',
            );
        }
    }

    /**
     * A role name: trimmed, 1–60 characters.
     */
    private function validName(string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        if ($name === '' || mb_strlen($name) > 60) {
            throw new BadRequestException('A role name is 1 to 60 characters.');
        }

        return $name;
    }

    /**
     * Role names are unique, whatever their case.
     */
    private function requireUniqueName(string $name, ?int $exceptId): void
    {
        foreach ($this->roles()->find()->select(['id', 'name'])->disableHydration()->all() as $row) {
            if ((int)$row['id'] !== $exceptId && mb_strtolower((string)$row['name']) === mb_strtolower($name)) {
                throw new BadRequestException("There is already a role called \"{$row['name']}\".");
            }
        }
    }

    /**
     * A new role's code: a slug of its first name, never reused.
     */
    private function newCode(string $name): string
    {
        $base = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-') ?: 'role';
        $base = substr($base, 0, 24);
        $taken = $this->roles()->find()->select(['code'])->disableHydration()->all()->extract('code')->toList();
        $taken = [...$taken, 'owner', 'admin', 'receptionist', self::PLATFORM_CODE];
        $code = $base;
        $n = 2;
        while (in_array($code, $taken, true)) {
            $code = $base . '-' . $n++;
        }

        return $code;
    }

    /**
     * A description: trimmed, at most 500 characters, null when empty.
     */
    private function cleanDescription(?string $description): ?string
    {
        $description = trim((string)$description);
        if (mb_strlen($description) > 500) {
            throw new BadRequestException('A role description is at most 500 characters.');
        }

        return $description === '' ? null : $description;
    }

    /**
     * A custom role's permissions: property permissions from the catalog,
     * never `platform.*` (PH-D3), at least one, each once.
     *
     * @param array<mixed> $permissions As sent.
     * @return list<string>
     */
    private function validPermissions(array $permissions): array
    {
        $list = array_values(array_unique(array_map('strval', $permissions)));
        if ($list === []) {
            throw new BadRequestException('A role needs at least one permission.');
        }
        foreach ($list as $permission) {
            if (!in_array($permission, Permissions::ALL, true)) {
                throw new BadRequestException("\"$permission\" isn't a permission the platform defines.");
            }
            if (str_starts_with($permission, 'platform.')) {
                throw new BadRequestException('Platform permissions are reserved for the Platform Owner.');
            }
        }

        return $list;
    }

    /**
     * The grants a role ends up with, replaying its events.
     *
     * @return list<string>
     */
    private function replay(int $roleId): array
    {
        $held = [];
        $rows = $this->events()->find()
            ->select(['event_type', 'permission'])
            ->where(['role_id' => $roleId, 'permission IS NOT' => null])
            ->orderBy(['id' => 'ASC'])->disableHydration()->all();
        foreach ($rows as $row) {
            if ($row['event_type'] === RoleEventsTable::PERMISSION_REVOKED) {
                unset($held[$row['permission']]);
            } else {
                $held[$row['permission']] = true;
            }
        }

        return array_keys($held);
    }

    /**
     * The permissions a role holds in the data.
     *
     * @return list<string>
     */
    private function held(int $roleId): array
    {
        return $this->grants()->find()->select(['permission'])->where(['role_id' => $roleId])
            ->disableHydration()->all()->extract('permission')->toList();
    }

    /**
     * A deploy's context: no actor, the migration (or release) as the request id.
     */
    private function context(string $reason, string $migration): EventContext
    {
        $id = str_starts_with($migration, 'release-') ? $migration : 'migration-' . $migration;

        return new EventContext(null, null, null, $id, EventContext::SOURCE_SYSTEM, $reason);
    }

    /**
     * An import's context, dated by the row's own recorded time (now when it has none).
     */
    private function importContext(string $table, int $id, mixed $created): EventContext
    {
        $at = $created instanceof DateTime ? $created : ($created !== null ? new DateTime((string)$created) : null);

        return new EventContext(null, null, null, "import-$table-$id", EventContext::SOURCE_IMPORT, null, $at);
    }

    /**
     * A change without a reason is refused.
     */
    private function requireReason(string $reason): void
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A role definition changes only with a reason.');
        }
    }

    /**
     * Whether role_events exists yet (a fresh database migrates in order).
     */
    private function ledgerReady(): bool
    {
        return in_array('role_events', $this->connection->getSchemaCollection()->listTables(), true);
    }

    /**
     * The roles table.
     */
    private function roles(): RolesTable
    {
        /** @var \App\Model\Table\RolesTable */
        return TableRegistry::getTableLocator()->get('Roles');
    }

    /**
     * The grants table.
     */
    private function grants(): RolePermissionsTable
    {
        /** @var \App\Model\Table\RolePermissionsTable */
        return TableRegistry::getTableLocator()->get('RolePermissions');
    }

    /**
     * The ledger.
     */
    private function events(): RoleEventsTable
    {
        /** @var \App\Model\Table\RoleEventsTable */
        return TableRegistry::getTableLocator()->get('RoleEvents');
    }
}
