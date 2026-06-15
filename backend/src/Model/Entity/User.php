<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * User entity.
 *
 * @property int $id
 * @property int|null $property_id
 * @property string $role        owner | admin | receptionist
 * @property string $name
 * @property string $email
 * @property string $password
 * @property string|null $api_token
 * @property \Cake\I18n\DateTime|null $token_expires
 * @property bool $is_active
 * @property \Cake\I18n\DateTime|null $created
 * @property \Cake\I18n\DateTime|null $modified
 */
class User extends Entity
{
    protected array $_accessible = [
        'property_id' => true,
        'role' => true,
        'name' => true,
        'email' => true,
        'password' => true,
        'is_active' => true,
        '*' => false,
    ];

    // Never expose secrets in API responses.
    protected array $_hidden = ['password', 'api_token'];

    /**
     * Hash the password whenever it is set.
     *
     * Uses PHP's native bcrypt hashing so the skeleton has no extra
     * dependency. Swap for cakephp/authentication's hasher when that
     * plugin is added for production.
     */
    protected function _setPassword(string $password): ?string
    {
        if (strlen($password) === 0) {
            return null;
        }

        return password_hash($password, PASSWORD_DEFAULT);
    }

    /**
     * Verify a plaintext password against the stored hash.
     */
    public function verifyPassword(string $password): bool
    {
        return password_verify($password, (string)$this->password);
    }
}
