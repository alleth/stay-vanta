<?php
declare(strict_types=1);

namespace App\Test\TestCase\Auth;

use App\Auth\LoginThrottle;
use Cake\Cache\Cache;
use Cake\TestSuite\TestCase;

/**
 * The failed-sign-in throttle.
 *
 * Counters live in the cache rather than the database, so this needs no
 * fixtures — but it does need the cache emptied between cases, or one test's
 * lockout leaks into the next.
 */
class LoginThrottleTest extends TestCase
{
    private LoginThrottle $throttle;
    private string $email = 'throttle-test@example.invalid';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::clear(LoginThrottle::CACHE_CONFIG);
        $this->throttle = new LoginThrottle();
    }

    protected function tearDown(): void
    {
        Cache::clear(LoginThrottle::CACHE_CONFIG);
        parent::tearDown();
    }

    public function testAFreshAddressMayTryToSignIn(): void
    {
        $this->assertNull($this->throttle->retryAfter($this->email));
    }

    public function testItTakesTheFullAllowanceToLock(): void
    {
        for ($i = 1; $i < LoginThrottle::MAX_ATTEMPTS; $i++) {
            $this->throttle->recordFailure($this->email);
            $this->assertNull(
                $this->throttle->retryAfter($this->email),
                "should still be allowed after $i failure(s)",
            );
        }

        $this->throttle->recordFailure($this->email);
        $this->assertNotNull($this->throttle->retryAfter($this->email));
    }

    public function testALockedAddressIsToldHowLongToWait(): void
    {
        for ($i = 0; $i < LoginThrottle::MAX_ATTEMPTS; $i++) {
            $this->throttle->recordFailure($this->email);
        }

        $wait = $this->throttle->retryAfter($this->email);
        $this->assertGreaterThan(0, $wait);
        $this->assertLessThanOrEqual(LoginThrottle::LOCKOUT_SECONDS, $wait);
    }

    public function testTheLockFollowsTheAddressNotItsSpelling(): void
    {
        for ($i = 0; $i < LoginThrottle::MAX_ATTEMPTS; $i++) {
            $this->throttle->recordFailure(strtoupper($this->email));
        }

        // Otherwise an attacker just varies the capitalisation.
        $this->assertNotNull($this->throttle->retryAfter($this->email));
    }

    public function testOneAddressLockingOutDoesNotAffectAnother(): void
    {
        for ($i = 0; $i < LoginThrottle::MAX_ATTEMPTS; $i++) {
            $this->throttle->recordFailure($this->email);
        }

        $this->assertNull($this->throttle->retryAfter('someone-else@example.invalid'));
    }

    public function testACorrectPasswordClearsTheSlate(): void
    {
        // Mistyping a few times before getting it right must not count toward
        // a later lockout.
        for ($i = 0; $i < LoginThrottle::MAX_ATTEMPTS - 1; $i++) {
            $this->throttle->recordFailure($this->email);
        }
        $this->throttle->clear($this->email);

        $this->throttle->recordFailure($this->email);
        $this->assertNull($this->throttle->retryAfter($this->email));
    }
}
