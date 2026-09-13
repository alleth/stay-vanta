<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * Users model.
 *
 * @method \App\Model\Entity\User newEmptyEntity()
 * @method \App\Model\Entity\User get(mixed $primaryKey, array $options = [])
 */
class UsersTable extends Table
{
    public const ROLES = ['owner', 'admin', 'receptionist'];

    /** Days a freshly issued API token stays valid. */
    public const TOKEN_LIFETIME_DAYS = 30;

    /**
     * Issue a new API token: the plaintext to hand the client, and the digest
     * to store. Only the digest is ever written to `users.api_token`.
     *
     * @return array{0: string, 1: string} [plaintext, digest]
     */
    public static function issueToken(): array
    {
        $token = bin2hex(random_bytes(32));

        return [$token, self::hashToken($token)];
    }

    /**
     * The stored form of an API token.
     *
     * A plain SHA-256, deliberately: unlike a password, the token is already
     * 256 bits of `random_bytes`, so there is nothing to brute-force and no
     * need for a salt or a work factor — which would only slow down every
     * authenticated request. Hashing means a leaked database (a backup, a
     * console session, an injection found elsewhere) yields no usable
     * sessions. Hex output is 64 characters, exactly the column width.
     */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('users');
        $this->setDisplayField('name');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');

        $this->belongsTo('Properties');
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('name')
            ->maxLength('name', 150)
            ->requirePresence('name', 'create')
            ->notEmptyString('name');

        $validator
            ->email('email')
            ->requirePresence('email', 'create')
            ->notEmptyString('email')
            ->add('email', 'unique', [
                'rule' => 'validateUnique',
                'provider' => 'table',
                'message' => 'This email is already in use.',
            ]);

        $validator
            ->scalar('password')
            ->minLength('password', 8)
            ->requirePresence('password', 'create')
            ->notEmptyString('password');

        $validator
            ->inList('role', self::ROLES)
            ->requirePresence('role', 'create');

        return $validator;
    }
}
