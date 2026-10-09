<?php
declare(strict_types=1);

use App\Platform\Enforcement;
use Cake\Cache\Cache;
use Cake\ORM\TableRegistry;
use Migrations\BaseMigration;

/**
 * Enforcement-setting audit (G6): record the phase in force at release once,
 * as `enforcement_phase_recorded` (source `import`, no actor, no reason: who
 * set it and why were never recorded), seeded from the environment variable
 * or `report` when unset, and note the ceiling seen then. The self-check is
 * logged. Idempotent (App\Platform\Enforcement::baseline()).
 */
class RecordEnforcementBaseline extends BaseMigration
{
    /**
     * @return void
     */
    public function up(): void
    {
        TableRegistry::getTableLocator()->clear();
        Cache::clear('_cake_model_');
        Enforcement::baseline();
        Enforcement::check();
    }

    /**
     * Remove only what the baseline added (a rollback restores code, not data;
     * nothing recorded after it is touched).
     *
     * @return void
     */
    public function down(): void
    {
        $this->execute("DELETE FROM platform_setting_events WHERE event_type = 'enforcement_phase_recorded'");
    }
}
