<?php
declare(strict_types=1);

namespace App\Controller\Api;

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

        $users = $this->fetchTable('Users');
        /** @var \App\Model\Entity\User|null $user */
        $user = $users->find()->where(['email' => $email, 'is_active' => true])->first();

        if ($user === null || !$user->verifyPassword($password)) {
            $this->response = $this->response->withStatus(401);
            $this->set('error', 'Invalid credentials.');
            $this->viewBuilder()->setOption('serialize', ['error']);

            return;
        }

        // Issue a fresh opaque token (30-day expiry).
        $user->api_token = bin2hex(random_bytes(32));
        $user->token_expires = (new DateTime())->modify('+30 days');
        $users->saveOrFail($user);

        $this->set([
            'token' => $user->api_token,
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
