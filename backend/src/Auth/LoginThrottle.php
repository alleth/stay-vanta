<?php
declare(strict_types=1);

namespace App\Auth;

use Cake\Cache\Cache;

/**
 * Rate limit for failed sign-ins, keyed on the email address that was tried.
 *
 * Passwords here are set by an admin rather than chosen by the person using
 * them, with an eight-character minimum and no complexity rule, so they tend
 * to be short and patterned — exactly the case online guessing is good at.
 * Five wrong answers for one address buys a fifteen-minute pause.
 *
 * Keyed on email, deliberately not on IP. The API sits behind Railway's proxy
 * and nothing configures a trusted proxy, so `clientIp()` reports the proxy
 * for every caller: an IP limit would count the whole world as one visitor and
 * lock out every property at once. Deriving the address from X-Forwarded-For
 * instead would make the key attacker-controlled, and so trivially evaded. The
 * email key has neither problem — an attacker going after an account has to
 * name it — and it is the dimension that actually defends an account. Adding
 * an IP limit later means configuring trusted proxies first.
 *
 * Counters live in the cache rather than a table: they are worth keeping for
 * minutes, not forever, and losing them to a redeploy costs nothing.
 */
final class LoginThrottle
{
    /** Cache config holding the counters (see config/app.php). */
    public const CACHE_CONFIG = 'login_throttle';

    /** Failures for one address before it is paused. */
    public const MAX_ATTEMPTS = 5;

    /** How long that pause lasts. */
    public const LOCKOUT_SECONDS = 900;

    /**
     * Seconds the caller still has to wait, or null when an attempt is allowed.
     */
    public function retryAfter(string $email): ?int
    {
        $entry = Cache::read($this->key($email), self::CACHE_CONFIG);
        if (!is_array($entry) || empty($entry['locked_until'])) {
            return null;
        }

        $remaining = (int)$entry['locked_until'] - time();

        return $remaining > 0 ? $remaining : null;
    }

    /**
     * Count one failed attempt, locking the address once it hits the limit.
     * Further failures while locked push the window out again.
     */
    public function recordFailure(string $email): void
    {
        $key = $this->key($email);
        $entry = Cache::read($key, self::CACHE_CONFIG);
        $count = is_array($entry) ? (int)($entry['count'] ?? 0) + 1 : 1;

        Cache::write($key, [
            'count' => $count,
            'locked_until' => $count >= self::MAX_ATTEMPTS ? time() + self::LOCKOUT_SECONDS : null,
        ], self::CACHE_CONFIG);
    }

    /**
     * Forget an address's failures — called when it signs in successfully, so
     * a few fat-fingered attempts before a correct password cost nothing.
     */
    public function clear(string $email): void
    {
        Cache::delete($this->key($email), self::CACHE_CONFIG);
    }

    /**
     * Hashed, and lowercased first so "A@b.com" and "a@b.com" share a counter.
     * The hash keeps the address itself off disk — the file cache would
     * otherwise write it into a filename.
     */
    private function key(string $email): string
    {
        return hash('sha256', mb_strtolower(trim($email)));
    }
}
