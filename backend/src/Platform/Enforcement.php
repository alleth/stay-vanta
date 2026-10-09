<?php
declare(strict_types=1);

namespace App\Platform;

use App\Event\EventContext;
use App\Model\Subscription;
use App\Model\Table\PlatformSettingEventsTable;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\Http\Exception\BadRequestException;
use Cake\Log\Log;
use Cake\ORM\TableRegistry;

/**
 * The subscription-enforcement phase (final review G6, approved 2026-10-09 as
 * E-D1–E-D6): `report` → `grace` → `read_only` → `suspend` (B5).
 *
 * - **Source of truth:** the `subscription_enforcement` row of
 *   `platform_settings`, changed only by change(), which records who, before,
 *   after, why, the request id and the properties it affected
 *   (`platform_setting_events`). No row yet means `report`.
 * - **Ceiling:** `APP_SUBSCRIPTION_ENFORCEMENT` (`App.subscriptionEnforcement`)
 *   is an emergency restriction: it may lower the effective phase, never
 *   raise it. Unset means no ceiling; an unknown value counts as `report`.
 *   observeCeiling() records each new value the first time the app sees it:
 *   when it was observed, not when (or by whom) it was set.
 * - **Rules (E-D3):** forward one phase at a time; back to any earlier phase;
 *   a reason both ways; the same phase is refused. Lock, check, change,
 *   record, in one transaction.
 */
final class Enforcement
{
    public const KEY = 'subscription_enforcement';
    /** The last ceiling value the app observed (null = none), for observeCeiling(). */
    public const CEILING_KEY = 'subscription_enforcement_ceiling_seen';

    /** Phase order. */
    private const RANK = [
        Subscription::MODE_REPORT => 0,
        Subscription::MODE_GRACE => 1,
        Subscription::MODE_READ_ONLY => 2,
        Subscription::MODE_SUSPEND => 3,
    ];

    /**
     * The phase the Platform Owner set (the database), before any ceiling.
     */
    public static function phase(): string
    {
        $value = self::value(self::KEY);

        return isset(self::RANK[$value]) ? $value : Subscription::MODE_REPORT;
    }

    /**
     * The emergency ceiling, or null when none is set.
     */
    public static function ceiling(): ?string
    {
        $raw = Configure::read('App.subscriptionEnforcement');
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!isset(self::RANK[(string)$raw])) {
            Log::warning(sprintf('unknown APP_SUBSCRIPTION_ENFORCEMENT "%s": treated as a report ceiling', $raw));

            return Subscription::MODE_REPORT;
        }

        return (string)$raw;
    }

    /**
     * The phase that applies: the set phase, lowered by the ceiling if any.
     */
    public static function effective(): string
    {
        return self::lower(self::phase(), self::ceiling());
    }

    /**
     * Change the phase (E-D3): one step forward or any step back, with a
     * reason, under a lock on the setting row. A refused change records nothing.
     *
     * @param \App\Event\EventContext $context The Platform Owner's request (with its reason).
     * @param string $to The new phase.
     * @return \Cake\Datasource\EntityInterface The recorded event.
     */
    public static function change(EventContext $context, string $to): EntityInterface
    {
        if (!isset(self::RANK[$to])) {
            throw new BadRequestException('phase must be report, grace, read_only or suspend.');
        }
        // The phase in force before the first change is recorded first, as it is.
        self::baseline();
        $settings = TableRegistry::getTableLocator()->get('PlatformSettings');

        return $settings->getConnection()->transactional(function () use ($context, $to, $settings) {
            $setting = self::lockedSetting(self::KEY, Subscription::MODE_REPORT);
            $current = (string)$setting->get('value');
            $from = isset(self::RANK[$current]) ? $current : Subscription::MODE_REPORT;
            if ($to === $from) {
                throw new BadRequestException("Enforcement is already $from.");
            }
            if (self::RANK[$to] > self::RANK[$from] + 1) {
                throw new BadRequestException(sprintf(
                    'Enforcement moves forward one phase at a time: from %s the next is %s.',
                    $from,
                    array_search(self::RANK[$from] + 1, self::RANK, true),
                ));
            }
            $raising = self::RANK[$to] > self::RANK[$from];
            $ceiling = self::ceiling();
            $before = self::counts($from);
            $after = self::counts($to);

            $setting->set('value', $to);
            $settings->saveOrFail($setting, ['ledger' => true, 'atomic' => false]);

            return self::events()->record(
                $context,
                $raising
                    ? PlatformSettingEventsTable::ENFORCEMENT_PHASE_RAISED
                    : PlatformSettingEventsTable::ENFORCEMENT_PHASE_LOWERED,
                $setting,
                [
                    'columns' => ['value_before' => $from, 'value_after' => $to],
                    'changes' => [
                        'phase' => ['before' => $from, 'after' => $to],
                        'effective' => [
                            'before' => self::lower($from, $ceiling),
                            'after' => self::lower($to, $ceiling),
                        ],
                        'ceiling' => $ceiling,
                    ],
                    'snapshot' => [
                        'properties' => $after['total'],
                        'enforced_before' => $before['enforced'],
                        'enforced_after' => $after['enforced'],
                        'moved_stricter' => self::movedStricter($from, $to),
                    ],
                ],
            );
        });
    }

    /**
     * The phase in force when G6 is released, recorded once (the baseline):
     * the value the environment held (or `report`), with no actor and no
     * reason, because who set it and why were never recorded. Also notes the
     * ceiling seen then, so observeCeiling() doesn't record it again.
     * Idempotent: does nothing once the setting exists.
     *
     * @return bool Whether it recorded the baseline now.
     */
    public static function baseline(): bool
    {
        $settings = TableRegistry::getTableLocator()->get('PlatformSettings');

        return $settings->getConnection()->transactional(function () use ($settings): bool {
            if ($settings->exists(['setting_key' => self::KEY])) {
                return false;
            }
            $ceiling = self::ceiling();
            $phase = $ceiling ?? Subscription::MODE_REPORT;
            $setting = self::lockedSetting(self::KEY, $phase);
            self::lockedSetting(self::CEILING_KEY, $ceiling);
            $counts = self::counts($phase);
            $context = new EventContext(
                null,
                null,
                null,
                'import-platform_settings-' . $setting->get('id'),
                EventContext::SOURCE_IMPORT,
            );
            self::events()->record($context, PlatformSettingEventsTable::ENFORCEMENT_PHASE_RECORDED, $setting, [
                'columns' => ['value_before' => null, 'value_after' => $phase],
                'changes' => [
                    'phase' => ['before' => null, 'after' => $phase],
                    'effective' => ['before' => null, 'after' => self::lower($phase, $ceiling)],
                    'ceiling' => $ceiling,
                    'set_before_g6' => 'by whom, when and why not recorded',
                ],
                'snapshot' => ['properties' => $counts['total'], 'enforced_after' => $counts['enforced']],
            ]);

            return true;
        });
    }

    /**
     * Record a ceiling the app hasn't seen before (at start-up). The event
     * says when the value was observed; who changed it in Railway, and when,
     * the app can't know and doesn't guess. Idempotent.
     *
     * @return bool Whether a new value was recorded.
     */
    public static function observeCeiling(): bool
    {
        $settings = TableRegistry::getTableLocator()->get('PlatformSettings');

        return $settings->getConnection()->transactional(function () use ($settings): bool {
            $ceiling = self::ceiling();
            $seen = self::lockedSetting(self::CEILING_KEY, null, false);
            if ($seen === null) {
                // Before the baseline nothing is compared; baseline() notes it.
                return false;
            }
            $previous = $seen->get('value');
            if ($previous === $ceiling) {
                return false;
            }
            $seen->set('value', $ceiling);
            $settings->saveOrFail($seen, ['ledger' => true, 'atomic' => false]);
            $phase = self::phase();
            self::events()->record(
                EventContext::system(null, 'enforcement-ceiling'),
                PlatformSettingEventsTable::ENFORCEMENT_CEILING_OBSERVED,
                $seen,
                [
                    'columns' => ['value_before' => $previous, 'value_after' => $ceiling],
                    'changes' => [
                        'ceiling' => ['before' => $previous, 'after' => $ceiling],
                        'effective' => [
                            'before' => self::lower($phase, $previous),
                            'after' => self::lower($phase, $ceiling),
                        ],
                        'observed_not_set' => 'the time is when the app first saw this value',
                    ],
                ],
            );

            return true;
        });
    }

    /**
     * What each phase would enforce now (E-D6): properties per stage by their
     * dates, and per phase the stages it would enforce. Counts only.
     *
     * @return array<string, mixed>
     */
    public static function preview(): array
    {
        $byStage = [];
        $byPhase = [];
        foreach (array_keys(self::RANK) as $mode) {
            $counts = self::counts($mode);
            $byPhase[$mode] = $counts['enforced'];
            $byStage = $counts['stages'];
        }

        return ['properties' => array_sum($byStage), 'stages' => $byStage, 'enforced_by_phase' => $byPhase];
    }

    /**
     * The self-check: one baseline, a history whose each change starts where
     * the last ended, the setting equal to the last change, and the ceiling
     * seen equal to the ceiling now.
     *
     * @return array<string, mixed>
     */
    public static function check(): array
    {
        $events = self::events();
        $setting = TableRegistry::getTableLocator()->get('PlatformSettings')->find()
            ->where(['setting_key' => self::KEY])->first();
        $rows = $setting === null ? [] : $events->find()
            ->where(['platform_setting_id' => $setting->get('id')])
            ->orderBy(['id' => 'ASC'])->all()->toList();
        $baselines = count(array_filter(
            $rows,
            fn($r) => $r->get('event_type') === PlatformSettingEventsTable::ENFORCEMENT_PHASE_RECORDED,
        ));
        $chain = true;
        $last = null;
        foreach ($rows as $r) {
            if ($last !== null && $r->get('value_before') !== $last) {
                $chain = false;
            }
            $last = $r->get('value_after');
        }
        $ceilingSeen = self::value(self::CEILING_KEY);
        $result = [
            'phase' => self::phase(),
            'ceiling' => self::ceiling(),
            'effective' => self::effective(),
            'baselines' => $baselines,
            'changes' => count($rows) - $baselines,
            'chain_ok' => $chain && ($setting === null || $last === $setting->get('value')),
            'ceiling_seen_ok' => $setting === null || $ceilingSeen === self::ceiling(),
        ];
        $result['complete'] = $setting !== null && $baselines === 1
            && $result['chain_ok'] && $result['ceiling_seen_ok'];
        $line = sprintf(
            'enforcement check: phase %s, ceiling %s, effective %s; %d baseline, %d change(s); '
            . 'history %s; ceiling seen %s',
            $result['phase'],
            $result['ceiling'] ?? 'none',
            $result['effective'],
            $baselines,
            $result['changes'],
            $result['chain_ok'] ? 'consistent' : 'INCONSISTENT',
            $result['ceiling_seen_ok'] ? 'up to date' : 'NOT RECORDED',
        );
        $result['complete'] ? Log::info($line) : Log::warning($line);

        return $result;
    }

    /**
     * The lower of a phase and a ceiling.
     */
    public static function lower(string $phase, ?string $ceiling): string
    {
        if ($ceiling === null || !isset(self::RANK[$ceiling])) {
            return $phase;
        }

        return self::RANK[$ceiling] < self::RANK[$phase] ? $ceiling : $phase;
    }

    /**
     * Properties per stage by date, and per stage as `$mode` would enforce it.
     *
     * @return array{total: int, stages: array<string, int>, enforced: array<string, int>}
     */
    private static function counts(string $mode): array
    {
        $empty = [
            Subscription::ACTIVE => 0, Subscription::GRACE => 0,
            Subscription::READ_ONLY => 0, Subscription::SUSPENDED => 0,
        ];
        $stages = $empty;
        $enforced = $empty;
        $total = 0;
        foreach (TableRegistry::getTableLocator()->get('Properties')->find()->all() as $property) {
            $s = Subscription::of($property, null, $mode)->toArray();
            $stages[$s['stage']]++;
            $enforced[$s['enforced']]++;
            $total++;
        }

        return ['total' => $total, 'stages' => $stages, 'enforced' => $enforced];
    }

    /**
     * How many properties a change from `$from` to `$to` puts under a stricter
     * enforced stage (0 for a lowering).
     */
    private static function movedStricter(string $from, string $to): int
    {
        $rank = [
            Subscription::ACTIVE => 0, Subscription::GRACE => 1,
            Subscription::READ_ONLY => 2, Subscription::SUSPENDED => 3,
        ];
        $moved = 0;
        foreach (TableRegistry::getTableLocator()->get('Properties')->find()->all() as $property) {
            $before = Subscription::of($property, null, $from)->enforced();
            $after = Subscription::of($property, null, $to)->enforced();
            $moved += $rank[$after] > $rank[$before] ? 1 : 0;
        }

        return $moved;
    }

    /**
     * A setting's value, or null when it doesn't exist.
     */
    private static function value(string $key): ?string
    {
        $row = TableRegistry::getTableLocator()->get('PlatformSettings')->find()
            ->select(['value'])->where(['setting_key' => $key])->disableHydration()->first();

        return $row === null ? null : $row['value'];
    }

    /**
     * The setting row under a `FOR UPDATE` lock, created with `$default`
     * when missing (unless `$create` is false).
     */
    private static function lockedSetting(string $key, ?string $default, bool $create = true): ?EntityInterface
    {
        $settings = TableRegistry::getTableLocator()->get('PlatformSettings');
        $row = $settings->find()->where(['setting_key' => $key])->epilog('FOR UPDATE')->first();
        if ($row === null && $create) {
            $row = $settings->newEntity(['setting_key' => $key, 'value' => $default], [
                'accessibleFields' => ['*' => true],
            ]);
            $settings->saveOrFail($row, ['ledger' => true, 'atomic' => false]);
        }

        return $row;
    }

    /**
     * The ledger.
     */
    private static function events(): PlatformSettingEventsTable
    {
        /** @var \App\Model\Table\PlatformSettingEventsTable */
        return TableRegistry::getTableLocator()->get('PlatformSettingEvents');
    }
}
