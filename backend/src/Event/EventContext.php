<?php
declare(strict_types=1);

namespace App\Event;

use Cake\I18n\DateTime;
use Cake\Utility\Text;
use InvalidArgumentException;

/**
 * Who is acting, for whom, why, and as part of which business action — built
 * once per request (AppController::eventContext()) and handed to every
 * ledger write. A ledger refuses to record without one.
 *
 * `now` is fixed when the context is made, so every event of one request
 * shares one clock as well as one correlation id.
 */
final class EventContext
{
    public const SOURCE_WEB = 'web';
    public const SOURCE_SYSTEM = 'system';
    public const SOURCE_IMPORT = 'import';

    private const SOURCES = [self::SOURCE_WEB, self::SOURCE_SYSTEM, self::SOURCE_IMPORT];

    public readonly DateTime $now;

    public readonly ?string $reason;

    /**
     * @param int|null $actorId The person acting; null only for system or import work.
     * @param string|null $actorRole Their role at this moment (roles change later; history mustn't).
     * @param int|null $propertyId The request's property, or null for platform-level work.
     * @param string $correlationId Shared by every event of one business action. Required.
     * @param string $source web | system | import.
     * @param string|null $reason Why, for actions that require one. Blank counts as none.
     * @param \Cake\I18n\DateTime|null $now Defaults to now.
     * @param bool $unauthenticated A web request that proved nobody (a failed sign-in): no actor.
     */
    public function __construct(
        public readonly ?int $actorId,
        public readonly ?string $actorRole,
        public readonly ?int $propertyId,
        public readonly string $correlationId,
        public readonly string $source = self::SOURCE_WEB,
        ?string $reason = null,
        ?DateTime $now = null,
        public readonly bool $unauthenticated = false,
    ) {
        if (trim($correlationId) === '') {
            throw new InvalidArgumentException('An event context needs a correlation id.');
        }
        if (!in_array($source, self::SOURCES, true)) {
            throw new InvalidArgumentException("Unknown event source '$source'.");
        }
        if ($actorId === null && $source === self::SOURCE_WEB && !$unauthenticated) {
            throw new InvalidArgumentException('A web event needs an actor; only system or import work has none.');
        }
        if ($unauthenticated && $actorId !== null) {
            throw new InvalidArgumentException('An unauthenticated event has no actor.');
        }
        $reason = $reason === null ? null : trim($reason);
        $this->reason = $reason === '' ? null : $reason;
        $this->now = $now ?? DateTime::now();
    }

    /**
     * For work no person started (scheduled jobs, maintenance). Gets its own
     * correlation id, prefixed with the job's name.
     */
    public static function system(?int $propertyId, string $job, ?string $reason = null): self
    {
        return new self(null, null, $propertyId, 'system-' . $job . '-' . Text::uuid(), self::SOURCE_SYSTEM, $reason);
    }

    /**
     * For a web request that proved nobody: a failed sign-in (build step 10).
     * It happened through the web, but nobody is known to have acted, so it
     * records no actor rather than guessing one.
     */
    public static function unauthenticated(?int $propertyId, string $correlationId): self
    {
        return new self(null, null, $propertyId, $correlationId, self::SOURCE_WEB, null, null, true);
    }

    /**
     * The same context with a reason. Everything else, including the clock
     * and the correlation id, is kept.
     */
    public function withReason(?string $reason): self
    {
        return new self(
            $this->actorId,
            $this->actorRole,
            $this->propertyId,
            $this->correlationId,
            $this->source,
            $reason,
            $this->now,
            $this->unauthenticated,
        );
    }
}
