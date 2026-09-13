<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Auth\LoginThrottle;
use App\Model\Table\UsersTable;
use Cake\I18n\DateTime;

/**
 * Authentication endpoints: login, current user, logout.
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
        $user = $users->find()->where(['email' => $email, 'is_active' => true])->first();

        if ($user === null || !$user->verifyPassword($password)) {
            // Counted whether or not the address exists — otherwise the
            // throttle itself would reveal which addresses do.
            $throttle->recordFailure($email);

            $this->response = $this->response->withStatus(401);
            $this->set('error', 'Invalid credentials.');
            $this->viewBuilder()->setOption('serialize', ['error']);

            return;
        }

        // A correct password clears the slate, so a few mistyped attempts
        // before it never accumulate toward a lockout.
        $throttle->clear($email);

        // Issue a fresh opaque token. Only its digest is stored — the
        // plaintext below is the client's single copy and is never persisted
        // or recoverable from the database.
        [$token, $digest] = UsersTable::issueToken();
        $user->api_token = $digest;
        $user->token_expires = (new DateTime())
            ->modify('+' . UsersTable::TOKEN_LIFETIME_DAYS . ' days');
        $users->saveOrFail($user);

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

        $this->currentUser->api_token = null;
        $this->currentUser->token_expires = null;
        $this->fetchTable('Users')->saveOrFail($this->currentUser);

        $this->set('ok', true);
        $this->viewBuilder()->setOption('serialize', ['ok']);
    }

    private function publicUser(\App\Model\Entity\User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'property_id' => $user->property_id,
        ];
    }
}
