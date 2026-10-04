<?php
declare(strict_types=1);

use App\Model\Finance\Restatement;
use Migrations\BaseMigration;

/**
 * Build step 7c-1: log, per property, what switching Collected to cash
 * movement (7c-2) will change: every changed day and month before and after,
 * the all-time invariant, and the cancelled paid POS sales that stay
 * collected. Read-only: it writes nothing but log lines, so the list is in
 * the deploy log before the switch is approved. Re-run any time with
 * `bin/cake cash_restatement`.
 */
class ReportCashRestatement extends BaseMigration
{
    /**
     * @return void
     */
    public function up(): void
    {
        Restatement::logAll();
    }

    /**
     * @return void
     */
    public function down(): void
    {
    }
}
