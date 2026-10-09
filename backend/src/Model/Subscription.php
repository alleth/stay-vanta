<?php
declare(strict_types=1);

namespace App\Model;

use App\Platform\Enforcement;
use Cake\Datasource\EntityInterface;
use Cake\I18n\Date;
use Cake\ORM\TableRegistry;

/**
 * Where a property's subscription stands (build step 10b, A7, approved
 * 2026-10-06): once it lapses, 7 days of grace, then 30 days read-only, then
 * suspension. Worked out on every request from dates already recorded; never
 * stored, so it can't drift from what the Platform Owner set.
 *
 * - **Lapsed on** (a hotel day, BusinessTime): the day after
 *   `subscription_expires_at` (the last paid day, B3); or, for a status set to
 *   `inactive` by hand, the day it was set, as the property change log
 *   recorded it (B4; for a status already inactive at the step 9 baseline,
 *   the baseline's day: the earliest it's known). The earlier of the two.
 * - **Stages:** grace = lapse day and the 6 after; read-only = the next 30;
 *   suspended from day 38. Example (paid through Oct 10): grace Oct 11–17,
 *   read-only Oct 18 – Nov 16, suspended from Nov 17.
 *
 * Enforcement rolls out in phases (B5): `report` (warnings only, the default),
 * `grace` (warnings as real deadlines, still nothing blocked), `read_only`
 * (read-only enforced; a suspended property stays read-only) or `suspend`
 * (everything). Since G6 the phase is App\Platform\Enforcement's (set by the
 * Platform Owner, recorded; `APP_SUBSCRIPTION_ENFORCEMENT` is only an
 * emergency ceiling). enforced() is the stage that actually applies.
 */
final class Subscription
{
    public const ACTIVE = 'active';
    public const GRACE = 'grace';
    public const READ_ONLY = 'read_only';
    public const SUSPENDED = 'suspended';

    public const GRACE_DAYS = 7;
    public const READ_ONLY_DAYS = 30;

    public const MODE_REPORT = 'report';
    public const MODE_GRACE = 'grace';
    public const MODE_READ_ONLY = 'read_only';
    public const MODE_SUSPEND = 'suspend';
    public const MODES = [self::MODE_REPORT, self::MODE_GRACE, self::MODE_READ_ONLY, self::MODE_SUSPEND];

    /** Order of the stages, for capping by mode. */
    private const RANK = [self::ACTIVE => 0, self::GRACE => 1, self::READ_ONLY => 2, self::SUSPENDED => 3];

    /** The furthest stage each mode enforces. */
    private const CAP = [
        self::MODE_REPORT => self::ACTIVE,
        self::MODE_GRACE => self::GRACE,
        self::MODE_READ_ONLY => self::READ_ONLY,
        self::MODE_SUSPEND => self::SUSPENDED,
    ];

    /**
     * @param string $stage Where the subscription stands by its dates.
     * @param \Cake\I18n\Date|null $lapsedOn The first day without a subscription, if it lapsed.
     * @param string $mode The enforcement phase.
     */
    public function __construct(
        public readonly string $stage,
        public readonly ?Date $lapsedOn,
        public readonly string $mode,
    ) {
    }

    /**
     * The stage of a property today.
     *
     * @param \Cake\Datasource\EntityInterface $property The property (subscription fields).
     * @param \Cake\I18n\Date|null $today Defaults to the hotel's today.
     * @param string|null $mode Defaults to the configured phase.
     */
    public static function of(EntityInterface $property, ?Date $today = null, ?string $mode = null): self
    {
        $today ??= BusinessTime::today();
        $mode ??= self::configuredMode();
        $lapsedOn = self::lapsedOn($property);
        if ($lapsedOn === null || $today < $lapsedOn) {
            return new self(self::ACTIVE, $lapsedOn, $mode);
        }
        $day = $lapsedOn->diffInDays($today);
        $stage = match (true) {
            $day < self::GRACE_DAYS => self::GRACE,
            $day < self::GRACE_DAYS + self::READ_ONLY_DAYS => self::READ_ONLY,
            default => self::SUSPENDED,
        };

        return new self($stage, $lapsedOn, $mode);
    }

    /**
     * The first day without a subscription, or null while it runs.
     *
     * @param \Cake\Datasource\EntityInterface $property The property.
     */
    public static function lapsedOn(EntityInterface $property): ?Date
    {
        $candidates = [];
        $expires = $property->get('subscription_expires_at');
        if ($expires !== null) {
            $candidates[] = (new Date($expires->format('Y-m-d')))->addDays(1);
        }
        if (($property->get('subscription_status') ?? 'active') !== 'active') {
            $candidates[] = self::inactiveSince((int)$property->get('id')) ?? BusinessTime::today();
        }
        if ($candidates === []) {
            return null;
        }
        usort($candidates, fn(Date $a, Date $b) => $a <=> $b);

        return $candidates[0];
    }

    /**
     * The hotel day the status was last set to inactive, from the property
     * change log (step 9): the latest change that made it inactive, or the
     * row's creation or baseline when it was already inactive then.
     */
    private static function inactiveSince(int $propertyId): ?Date
    {
        $rows = TableRegistry::getTableLocator()->get('ConfigChanges')->find()
            ->select(['changes', 'occurred_at'])
            ->where(['entity_type' => 'property', 'entity_id' => $propertyId])
            ->orderBy(['occurred_at' => 'DESC', 'id' => 'DESC'])
            ->all();
        foreach ($rows as $row) {
            $changes = (array)$row->get('changes');
            $after = $changes['subscription_status']['after'] ?? $changes['after']['subscription_status'] ?? null;
            if ($after === null) {
                continue;
            }
            if ($after !== 'inactive') {
                return null;
            }
            /** @var \Cake\I18n\DateTime $at */
            $at = $row->get('occurred_at');

            return new Date($at->setTimezone(BusinessTime::timezone())->format('Y-m-d'));
        }

        return null;
    }

    /**
     * The enforcement phase in force (B5, G6): the Platform Owner's setting,
     * lowered by the emergency ceiling if one is set.
     */
    public static function configuredMode(): string
    {
        return Enforcement::effective();
    }

    /**
     * The stage that actually applies: the stage, capped by the phase.
     */
    public function enforced(): string
    {
        $cap = self::CAP[$this->mode];

        return self::RANK[$this->stage] <= self::RANK[$cap] ? $this->stage : $cap;
    }

    /**
     * Whether changes are refused (read-only or suspended, as enforced).
     */
    public function isReadOnly(): bool
    {
        return in_array($this->enforced(), [self::READ_ONLY, self::SUSPENDED], true);
    }

    /**
     * Whether the property's staff are locked out (suspension, as enforced).
     */
    public function isSuspended(): bool
    {
        return $this->enforced() === self::SUSPENDED;
    }

    /**
     * The first read-only day.
     */
    public function readOnlyFrom(): ?Date
    {
        return $this->lapsedOn?->addDays(self::GRACE_DAYS);
    }

    /**
     * The first suspended day.
     */
    public function suspendedFrom(): ?Date
    {
        return $this->lapsedOn?->addDays(self::GRACE_DAYS + self::READ_ONLY_DAYS);
    }

    /**
     * As the screens and the Platform list see it.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'stage' => $this->stage,
            'enforced' => $this->enforced(),
            'mode' => $this->mode,
            'lapsed_on' => $this->lapsedOn?->format('Y-m-d'),
            'read_only_from' => $this->readOnlyFrom()?->format('Y-m-d'),
            'suspended_from' => $this->suspendedFrom()?->format('Y-m-d'),
        ];
    }
}
