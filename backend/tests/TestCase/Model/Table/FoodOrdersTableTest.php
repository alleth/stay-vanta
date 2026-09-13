<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Model\Table\FoodOrdersTable;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\TestCase;

/**
 * The statutory Senior/PWD discount split.
 *
 * Each beneficiary's share is snapshotted onto its own food_order_discounts
 * row and posted as its own negative invoice line, so the shares have to sum
 * to exactly the discount taken off the order total — a cent adrift and the
 * folio disagrees with the bill.
 */
class FoodOrdersTableTest extends TestCase
{
    use LocatorAwareTrait;

    protected FoodOrdersTable $FoodOrders;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var \App\Model\Table\FoodOrdersTable $table */
        $table = $this->getTableLocator()->get('FoodOrders');
        $this->FoodOrders = $table;
    }

    public function testNoBeneficiariesMeansNoDiscount(): void
    {
        $split = $this->FoodOrders->splitStatutoryDiscount(1000.0, 4, 0);

        $this->assertSame(0.0, $split['total']);
        $this->assertSame([], $split['shares']);
    }

    public function testLoneDinerGetsTwentyPercentOfTheWholeBill(): void
    {
        $split = $this->FoodOrders->splitStatutoryDiscount(1000.0, 1, 1);

        $this->assertSame(200.0, $split['total']);
        $this->assertSame([200.0], $split['shares']);
    }

    public function testOneSeniorAtATableOfFourDiscountsOnlyTheirShare(): void
    {
        // The whole point of the rule: 1000 split four ways is 250 each, and
        // 20% of that one share is 50 — not 200 off the table's bill.
        $split = $this->FoodOrders->splitStatutoryDiscount(1000.0, 4, 1);

        $this->assertSame(50.0, $split['total']);
        $this->assertSame([50.0], $split['shares']);
    }

    public function testTwoSeniorsAtOneTableEachGetTheirOwnShare(): void
    {
        $split = $this->FoodOrders->splitStatutoryDiscount(1000.0, 4, 2);

        $this->assertSame(100.0, $split['total']);
        $this->assertSame([50.0, 50.0], $split['shares']);
    }

    public function testALeftoverCentGoesToTheEarlierBeneficiaries(): void
    {
        // 100 * 2/3 * 20% is 13.333…, which rounds to 13.33. Split two ways
        // that's 6.665 each — impossible in cents, so one gets the extra.
        $split = $this->FoodOrders->splitStatutoryDiscount(100.0, 3, 2);

        $this->assertSame(13.33, $split['total']);
        $this->assertSame([6.67, 6.66], $split['shares']);
    }

    /**
     * The invariant the itemised folio depends on, across awkward numbers.
     */
    public function testSharesAlwaysSumToTheDiscountTaken(): void
    {
        $cases = [
            [99.99, 3, 2],
            [0.01, 1, 1],
            [1234.56, 7, 3],
            [10.0, 6, 5],
            [845.75, 9, 4],
        ];

        foreach ($cases as [$subtotal, $diners, $beneficiaries]) {
            $split = $this->FoodOrders->splitStatutoryDiscount($subtotal, $diners, $beneficiaries);

            // Compared in cents: summing floats is exactly the rounding trap
            // this method exists to avoid.
            $sumCents = array_sum(array_map(fn(float $s) => (int)round($s * 100), $split['shares']));

            $this->assertSame(
                (int)round($split['total'] * 100),
                $sumCents,
                sprintf(
                    'shares must sum to the total for %.2f / %d diners / %d beneficiaries',
                    $subtotal,
                    $diners,
                    $beneficiaries,
                ),
            );
            $this->assertCount($beneficiaries, $split['shares']);
        }
    }

    public function testDiscountNeverExceedsTheSubtotal(): void
    {
        // Beneficiaries can never outnumber diners (the controller rejects
        // that), so the worst case is everyone qualifying: 20% of the bill.
        $split = $this->FoodOrders->splitStatutoryDiscount(500.0, 3, 3);

        $this->assertSame(100.0, $split['total']);
        $this->assertLessThan(500.0, $split['total']);
    }
}
