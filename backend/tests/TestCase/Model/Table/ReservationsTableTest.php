<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Model\Entity\Reservation;
use App\Model\Table\ReservationsTable;
use Cake\I18n\Date;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\TestCase;

/**
 * Pricing rules for a booking.
 *
 * quote() is pure arithmetic over an entity, so these need no fixtures: the
 * entities are built in memory. It decides what a guest is actually charged,
 * and ReservationsController::postRoomCharge() itemises its output straight
 * onto the invoice — a wrong number here is a wrong number on a real folio.
 */
class ReservationsTableTest extends TestCase
{
    use LocatorAwareTrait;

    protected ReservationsTable $Reservations;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var \App\Model\Table\ReservationsTable $table */
        $table = $this->getTableLocator()->get('Reservations');
        $this->Reservations = $table;
    }

    /**
     * A booking over the given nights, with no discounts unless asked for.
     */
    private function booking(string $checkIn, string $checkOut, array $extra = []): Reservation
    {
        return new Reservation($extra + [
            'check_in' => new Date($checkIn),
            'check_out' => new Date($checkOut),
            'discount_type' => 'none',
            'discount_amount' => null,
            'promo_rate' => null,
        ]);
    }

    public function testNightsAreTheStayNotTheDatesTouched(): void
    {
        // Checking in on the 1st and out on the 4th is three nights, not four
        // — the same half-open range the availability rule uses.
        $this->assertSame(3, $this->Reservations->nights($this->booking('2026-03-01', '2026-03-04')));
    }

    public function testSubtotalIsRateTimesNights(): void
    {
        $quote = $this->Reservations->quote($this->booking('2026-03-01', '2026-03-04'), 1500.0);

        $this->assertSame(3, $quote['nights']);
        $this->assertSame(4500.0, $quote['subtotal']);
        $this->assertSame(0.0, $quote['discount']);
        $this->assertSame(4500.0, $quote['total']);
    }

    public function testPromoRateOverridesTheRoomRate(): void
    {
        // An OTA-negotiated nightly price wins over whatever the room's own
        // rate says; the base rate passed in is ignored entirely.
        $booking = $this->booking('2026-03-01', '2026-03-03', ['promo_rate' => 2000.0]);
        $quote = $this->Reservations->quote($booking, 1500.0);

        $this->assertSame(2000.0, $quote['nightly_rate']);
        $this->assertSame(4000.0, $quote['subtotal']);
    }

    public function testSeniorDiscountTakesTwentyPercent(): void
    {
        $booking = $this->booking('2026-03-01', '2026-03-04', ['discount_type' => 'senior']);
        $quote = $this->Reservations->quote($booking, 1500.0);

        $this->assertSame(900.0, $quote['statutory_discount']);
        $this->assertSame(0.0, $quote['referral_discount']);
        $this->assertSame(3600.0, $quote['total']);
    }

    public function testReferralIsAFlatAmountIndependentOfTheStatutoryOne(): void
    {
        $booking = $this->booking('2026-03-01', '2026-03-04', ['discount_amount' => 500.0]);
        $quote = $this->Reservations->quote($booking, 1500.0);

        $this->assertSame(0.0, $quote['statutory_discount']);
        $this->assertSame(500.0, $quote['referral_discount']);
        $this->assertSame(4000.0, $quote['total']);
    }

    public function testSeniorAndReferralStack(): void
    {
        // Either can happen to any booking, so they add up: 20% off 4500 is
        // 900, and the referral then comes off the 3600 that's left.
        $booking = $this->booking('2026-03-01', '2026-03-04', [
            'discount_type' => 'senior',
            'discount_amount' => 500.0,
        ]);
        $quote = $this->Reservations->quote($booking, 1500.0);

        $this->assertSame(900.0, $quote['statutory_discount']);
        $this->assertSame(500.0, $quote['referral_discount']);
        $this->assertSame(1400.0, $quote['discount']);
        $this->assertSame(3100.0, $quote['total']);
    }

    public function testReferralCannotDriveTheTotalNegative(): void
    {
        // A mistyped referral is capped at what's left after the statutory
        // discount, so the worst case is a free stay, never a refund owed.
        $booking = $this->booking('2026-03-01', '2026-03-04', [
            'discount_type' => 'pwd',
            'discount_amount' => 99999.0,
        ]);
        $quote = $this->Reservations->quote($booking, 1500.0);

        $this->assertSame(3600.0, $quote['referral_discount']);
        $this->assertSame(0.0, $quote['total']);
    }

    public function testAnUnresolvedRateQuotesZeroRatherThanFailing(): void
    {
        // resolveBaseRate() returns 0 when no rate is configured; the quote
        // has to stay coherent rather than produce a negative or a warning.
        $quote = $this->Reservations->quote($this->booking('2026-03-01', '2026-03-04'), 0.0);

        $this->assertSame(0.0, $quote['subtotal']);
        $this->assertSame(0.0, $quote['total']);
    }
}
