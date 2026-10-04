<?php
declare(strict_types=1);

use App\Model\Finance\Restatement;
use Migrations\BaseMigration;

/**
 * Build step 7c-2: the restatement list again, on the deploy that switches
 * Collected to cash movement, so a downpayment refund recorded between 7c-1
 * and the switch is in the log too. Read-only, like ReportCashRestatement.
 */
class ReportCashRestatementAtSwitch extends BaseMigration
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
