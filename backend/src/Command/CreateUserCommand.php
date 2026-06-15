<?php
declare(strict_types=1);

namespace App\Command;

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

        if (!$users->save($user)) {
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
