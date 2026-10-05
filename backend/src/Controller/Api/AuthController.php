<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Auth\LoginThrottle;
use App\Auth\Permissions;
use App\Event\EventContext;
use App\Model\Entity\User;
use App\Model\Table\AccessEventsTable;
use App\Model\Table\UsersTable;
use Cake\I18n\DateTime;
use Cake\Log\Log;
use Throwable;

/**
 * Authentication endpoints: login, current user, logout, and your own
 * sign-in history. Every sign-in, failure, lockout, refusal and sign-out is
 * recorded in access_events (build step 10).
 */
class AuthController extends AppController
{
    protected array $publicActions = ['login'];

    /**
     * POST /api/auth/login  { email, password } -> { token, user }
     */
    public function login(): void
    {
        $this->request->allowMethod('post');

        $email = (string)$this->request->getData('email');
        $password = (string)$this->request->getData('password');

        // Checked before the password is, so a locked address costs an
        // attacker a cache read rather than a bcrypt verification.
        $throttle = new LoginThrottle();
        $retryAfter = $throttle->retryAfter($email);
        if ($retryAfter !== null) {
            $this->response = $this->response
                ->withStatus(429)
                ->withHeader('Retry-After', (string)$retryAfter);
            $this->set('error', sprintf(
                'Too many failed sign-in attempts. Try again in about %d minute(s).',
                max(1, (int)ceil($retryAfter / 60)),
            ));
            $this->viewBuilder()->setOption('serialize', ['error']);

            return;
        }

        $users = $this->fetchTable('Users');
        /** @var \App\Model\Entity\User|null $user */
        $user = $users->find()->where(['email' => $email])->first();

        if ($user === null || !$user->verifyPassword($password)) {
            // Counted whether or not the address exists — otherwise the
            // throttle itself would reveal which addresses do.
            $locked = $throttle->recordFailure($email);
            if ($user !== null) {
                // Nobody was proven: no actor (step 10, A3).
                $context = EventContext::unauthenticated($this->propertyOf($user), $this->correlationId());
                $this->recordSignInAttempt($context, AccessEventsTable::SIGN_IN_FAILED, $user);
                if ($locked) {
                    $this->recordSignInAttempt($context, AccessEventsTable::SIGN_IN_LOCKED, $user);
                }
            } else {
                // No account to attach it to; the address isn't kept (A3).
                Log::info('sign-in failed for an address with no account');
            }

            $this->refuse();

            return;
        }

        if (!$user->is_active) {
            // The right password for a switched-off account: answered as
            // before (no hint that the address exists), recorded for whoever
            // manages it. The password was right, so the holder is the actor.
            $locked = $throttle->recordFailure($email);
            $this->recordSignInAttempt($this->contextFor($user), AccessEventsTable::SIGN_IN_REFUSED, $user);
            if ($locked) {
                $this->recordSignInAttempt($this->contextFor($user), AccessEventsTable::SIGN_IN_LOCKED, $user);
            }
            $this->refuse();

            return;
        }

        // A correct password clears the slate, so a few mistyped attempts
        // before it never accumulate toward a lockout.
        $throttle->clear($email);

        // Issue a fresh opaque token. Only its digest is stored — the
        // plaintext below is the client's single copy and is never persisted
        // or recoverable from the database. The token and its event are one
        // transaction: no sign-in without its record.
        [$token, $digest] = UsersTable::issueToken();
        $user = $users->getConnection()->transactional(function () use ($users, $user, $digest): User {
            /** @var \App\Model\Entity\User $locked */
            $locked = $users->find()->where(['Users.id' => $user->id])->epilog('FOR UPDATE')->firstOrFail();
            // Only one session per person: signing in here ends any other.
            $endedOther = $locked->api_token !== null
                && ($locked->token_expires === null || !$locked->token_expires->isPast());
            $locked->api_token = $digest;
            $locked->token_expires = (new DateTime())
                ->modify('+' . UsersTable::TOKEN_LIFETIME_DAYS . ' days');
            $users->saveOrFail($locked, ['atomic' => false]);
            $this->recordAccess(
                $this->contextFor($locked),
                AccessEventsTable::SIGNED_IN,
                $locked,
                $endedOther ? ['changes' => ['ended_other_session' => true]] : [],
                true,
            );

            return $locked;
        });

        $this->set([
            'token' => $token,
            'user' => $this->publicUser($user),
        ]);
        $this->viewBuilder()->setOption('serialize', ['token', 'user']);
    }

    /**
     * GET /api/auth/me -> { user }
     */
    public function me(): void
    {
        $this->request->allowMethod('get');
        $this->set('user', $this->publicUser($this->currentUser));
        $this->viewBuilder()->setOption('serialize', ['user']);
    }

    /**
     * POST /api/auth/logout -> { ok: true }
     */
    public function logout(): void
    {
        $this->request->allowMethod('post');

        $users = $this->fetchTable('Users');
        $users->getConnection()->transactional(function () use ($users): void {
            $this->currentUser->api_token = null;
            $this->currentUser->token_expires = null;
            $users->saveOrFail($this->currentUser, ['atomic' => false]);
            $this->recordAccess(
                $this->contextFor($this->currentUser),
                AccessEventsTable::SIGNED_OUT,
                $this->currentUser,
                [],
                true,
            );
        });

        $this->set('ok', true);
        $this->viewBuilder()->setOption('serialize', ['ok']);
    }

    /**
     * GET /api/auth/sign-ins[?page=] -> { events, page, has_more }
     *
     * Your own access history (step 10, A9): sign-ins and their devices,
     * failed attempts on your account, and what others did to it (a reset, a
     * deactivation). Needs no permission, like /auth/me: everyone may see
     * their own.
     */
    public function signIns(): void
    {
        $this->request->allowMethod('get');
        $this->respondWithAccessHistory((int)$this->currentUser->id);
    }

    /**
     * A person's own context: they're the actor, at their property.
     */
    private function contextFor(User $user): EventContext
    {
        return new EventContext((int)$user->id, $user->role, $this->propertyOf($user), $this->correlationId());
    }

    /**
     * The person's property, or null for the Platform Owner (a platform event).
     */
    private function propertyOf(User $user): ?int
    {
        return $user->property_id !== null ? (int)$user->property_id : null;
    }

    /**
     * A failed or refused attempt's record, best effort: the person still
     * gets their answer if it can't be written, and the log says so (with
     * the request id).
     */
    private function recordSignInAttempt(EventContext $context, string $type, User $user): void
    {
        try {
            $this->fetchTable('AccessEvents')->getConnection()->transactional(
                function () use ($context, $type, $user): void {
                    $this->recordAccess($context, $type, $user, [], true);
                },
            );
        } catch (Throwable $e) {
            Log::error(sprintf('could not record %s for user %d: %s', $type, (int)$user->id, $e->getMessage()));
        }
    }

    /**
     * The one answer to a failed or refused sign-in, so it never tells which.
     */
    private function refuse(): void
    {
        $this->response = $this->response->withStatus(401);
        $this->set('error', 'Invalid credentials.');
        $this->viewBuilder()->setOption('serialize', ['error']);
    }

    /**
     * The signed-in user as the SPA sees them.
     *
     * @return array<string, mixed>
     */
    private function publicUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'property_id' => $user->property_id,
            // What the screens may offer. Convenience only: every action
            // checks its permission on the server.
            'permissions' => Permissions::forRole($user->role)->toArray(),
        ];
    }
}
