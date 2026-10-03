<?php
declare(strict_types=1);

namespace App\Test\TestSuite;

use App\Model\Table\FoodOrderEventsTable;

/**
 * The POS ledger with no grace window, for testing the reason rule as it will
 * be once every compatibility window has closed.
 */
class StrictFoodOrderEventsTable extends FoodOrderEventsTable
{
    public const REASON_GRACE = [];
}
