<?php
declare(strict_types=1);

namespace App\Auth;

/**
 * The permissions one person holds for one request, read-only.
 *
 * It answers "may I do this?" only. Where they may do it is the request's
 * property scope (AppController::effectivePropertyId()), checked separately.
 */
final class PermissionSet
{
    /**
     * @var array<string, true>
     */
    private array $granted;

    /**
     * @param iterable<string> $permissions
     */
    public function __construct(iterable $permissions = [])
    {
        $granted = [];
        foreach ($permissions as $permission) {
            $granted[$permission] = true;
        }
        $this->granted = $granted;
    }

    /**
     * Whether this exact permission is held. There are no wildcards.
     */
    public function has(string $permission): bool
    {
        return isset($this->granted[$permission]);
    }

    /**
     * @return list<string> Sorted, for stable API output.
     */
    public function toArray(): array
    {
        $list = array_keys($this->granted);
        sort($list);

        return $list;
    }
}
