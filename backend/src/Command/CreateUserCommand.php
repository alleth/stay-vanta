<?php
declare(strict_types=1);

namespace App\Command;

use App\Event\EventContext;
use App\Model\Table\AccessEventsTable;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use LogicException;

/**
 * Create a user from the CLI. Handy for seeding the first platform owner:
 *
 *   bin/cake create_user --name "Owner" --email owner@stayvanta.test \
 *       --password secret123 --role owner
 *
 * Where the account's access comes from (Phase 3d-1, 3D-D5): the platform flag
 * for `--role owner`, else a membership with that built-in role at
 * `--property-id`. Nothing reads users.role / users.property_id back; they
 * are still written until 3d-2.
 */
class CreateUserCommand extends Command
{
    /**
     * @param \Cake\Console\ConsoleOptionParser $parser The parser.
     * @return \Cake\Console\ConsoleOptionParser
     */
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->addOption('name', ['required' => true, 'help' => 'Full name'])
            ->addOption('email', ['required' => true, 'help' => 'Login email'])
            ->addOption('password', ['required' => true, 'help' => 'Plaintext password (min 8 chars)'])
            ->addOption('role', [
                'default' => 'owner',
                'choices' => ['owner', 'admin', 'receptionist'],
                'help' => 'User role',
            ])
            ->addOption('property-id', ['help' => 'Property id (for admin/receptionist)']);
    }

    /**
     * @param \Cake\Console\Arguments $args The arguments.
     * @param \Cake\Console\ConsoleIo $io The console.
     * @return int
     */
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $role = (string)$args->getOption('role');
        $platform = $role === 'owner';
        $propertyId = $platform ? null : ((int)$args->getOption('property-id') ?: null);
        if (!$platform && $propertyId === null) {
            $io->error('--property-id is required for admin and receptionist accounts.');

            return static::CODE_ERROR;
        }

        $users = $this->fetchTable('Users');
        $user = $users->newEntity([
            'name' => $args->getOption('name'),
            'email' => $args->getOption('email'),
            'password' => $args->getOption('password'),
            // Legacy columns, written until 3d-2 and read by nothing.
            'role' => $role,
            'property_id' => $propertyId,
            'is_active' => true,
        ]);

        // Recorded as the system: nobody signed in made it (step 10).
        $work = function () use ($users, $user, $role, $platform, $propertyId): bool {
            if (!$users->save($user, ['atomic' => false])) {
                return false;
            }
            /** @var \App\Model\Table\AccessEventsTable $events */
            $events = $this->fetchTable('AccessEvents');
            $context = EventContext::system($propertyId, 'create-user');
            $events->record($context, AccessEventsTable::ACCOUNT_CREATED, $user, [
                'changes' => ['after' => [
                    'name' => $user->name,
                    'role' => $role,
                    'property_id' => $propertyId,
                    'is_active' => true,
                ]],
                // Before the membership exists: its property is named here.
                'propertyId' => $propertyId,
            ]);
            if ($platform) {
                $user->set('is_platform', true);
                $users->saveOrFail($user, ['atomic' => false]);
                $events->record($context, AccessEventsTable::PLATFORM_ACCESS_GRANTED, $user);

                return true;
            }
            /** @var \App\Model\Table\RolesTable $roles */
            $roles = $this->fetchTable('Roles');
            $roleId = $roles->idFor($role);
            if ($roleId === null) {
                throw new LogicException("No role '$role' to grant (are the presets seeded?).");
            }
            $memberships = $this->fetchTable('PropertyMemberships');
            $membership = $memberships->saveOrFail($memberships->newEntity([
                'user_id' => (int)$user->id,
                'property_id' => $propertyId,
                'role_id' => $roleId,
                'started_at' => $context->now,
            ], ['accessibleFields' => ['*' => true]]), ['atomic' => false]);
            $events->record($context, AccessEventsTable::MEMBERSHIP_GRANTED, $user, [
                'changes' => ['after' => ['property_id' => $propertyId, 'role' => $role]],
                'columns' => ['membership_id' => (int)$membership->id, 'role_id' => $roleId],
            ]);

            return true;
        };
        $saved = $users->getConnection()->transactional($work);
        if (!$saved) {
            $io->error('Could not create user:');
            foreach ($user->getErrors() as $field => $errors) {
                $io->error(sprintf('  %s: %s', $field, implode(', ', $errors)));
            }

            return static::CODE_ERROR;
        }

        $io->success(sprintf('Created %s user #%d <%s>', $role, $user->id, $user->email));

        return static::CODE_SUCCESS;
    }
}
