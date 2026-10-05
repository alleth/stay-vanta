<?php
declare(strict_types=1);

namespace App\Command;

use App\Event\EventContext;
use App\Model\Table\AccessEventsTable;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;

/**
 * Create a user from the CLI. Handy for seeding the first platform owner:
 *
 *   bin/cake create_user --name "Owner" --email owner@stayvanta.test \
 *       --password secret123 --role owner
 */
class CreateUserCommand extends Command
{
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
        $users = $this->fetchTable('Users');

        $user = $users->newEntity([
            'name' => $args->getOption('name'),
            'email' => $args->getOption('email'),
            'password' => $args->getOption('password'),
            'role' => $args->getOption('role'),
            'property_id' => $args->getOption('property-id') ?: null,
            'is_active' => true,
        ]);

        // Recorded as the system: nobody signed in made it (step 10).
        $saved = $users->getConnection()->transactional(function () use ($users, $user): bool {
            if (!$users->save($user, ['atomic' => false])) {
                return false;
            }
            $propertyId = $user->property_id !== null ? (int)$user->property_id : null;
            /** @var \App\Model\Table\AccessEventsTable $events */
            $events = $this->fetchTable('AccessEvents');
            $context = EventContext::system($propertyId, 'create-user');
            $events->record($context, AccessEventsTable::ACCOUNT_CREATED, $user, [
                'changes' => ['after' => [
                    'name' => $user->name,
                    'role' => $user->role,
                    'property_id' => $propertyId,
                    'is_active' => true,
                ]],
            ]);

            return true;
        });
        if (!$saved) {
            $io->error('Could not create user:');
            foreach ($user->getErrors() as $field => $errors) {
                $io->error(sprintf('  %s: %s', $field, implode(', ', $errors)));
            }

            return static::CODE_ERROR;
        }

        $io->success(sprintf('Created %s user #%d <%s>', $user->role, $user->id, $user->email));

        return static::CODE_SUCCESS;
    }
}
