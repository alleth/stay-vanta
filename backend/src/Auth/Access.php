<?php
declare(strict_types=1);

namespace App\Auth;

use App\Model\Subscription;
use Cake\Datasource\EntityInterface;

/**
 * What one signed-in person may do, and where, for one request (build step
 * 10b): the permissions they hold and the property those apply to. Resolved
 * once per request by AccessResolver.
 */
final class Access
{
    /**
     * @param \App\Auth\PermissionSet $permissions What they may do.
     * @param int|null $propertyId Where: their membership's property; null for the platform.
     * @param string|null $roleCode Their role there (`admin`, `receptionist`), or `owner` on the platform.
     * @param int|null $membershipId The membership it comes from, if any.
     * @param bool $platform Whether this is platform access (the platform flag).
     * @param \Cake\Datasource\EntityInterface|null $supportSession The open support session this
     *   access comes from (the Platform Owner reading one property, read-only, A6).
     * @param \App\Model\Subscription|null $subscription The property's subscription stage (A7).
     * @param list<string> $windDown Permissions usable only for named wind-down actions while
     *   the property is read-only (Permissions::WIND_DOWN).
     * @param list<string> $withheld Permissions the role grants but the subscription withholds now.
     */
    public function __construct(
        public readonly PermissionSet $permissions,
        public readonly ?int $propertyId,
        public readonly ?string $roleCode,
        public readonly ?int $membershipId,
        public readonly bool $platform,
        public readonly ?EntityInterface $supportSession = null,
        public readonly ?Subscription $subscription = null,
        public readonly array $windDown = [],
        public readonly array $withheld = [],
    ) {
    }

    /**
     * No access at all: no membership and no platform flag.
     */
    public static function none(): self
    {
        return new self(new PermissionSet(), null, null, null, false);
    }

    /**
     * A short fingerprint of what this access allows (Phase 3c-2, C-D3): it
     * changes when the role's grants, the property, the subscription's
     * narrowing or a support session change, and only then. Sent on every
     * response as X-Permissions-Version so open screens refresh; never used
     * for enforcement, which reads the grants on every request.
     */
    public function version(): string
    {
        $permissions = $this->permissions->toArray();
        sort($permissions);
        $windDown = $this->windDown;
        sort($windDown);

        return substr(hash('sha256', (string)json_encode([
            $permissions,
            $windDown,
            $this->propertyId,
            $this->membershipId,
            $this->platform,
            $this->supportSession?->get('id'),
        ])), 0, 12);
    }

    /**
     * Whether the person has anywhere to work: a property or the platform.
     */
    public function hasAccess(): bool
    {
        return $this->platform || $this->propertyId !== null;
    }
}
