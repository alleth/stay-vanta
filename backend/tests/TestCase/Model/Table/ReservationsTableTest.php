<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Model\Entity\Reservation;
use App\Model\Entity\ReservationDiscount;
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
     *
     * `reservation_discounts` is set even when empty: quote() falls back to
     * reading the rows for a saved booking when the association is absent, and
     * these entities are never saved.
     */
    private function booking(string $checkIn, string $checkOut, array $extra = []): Reservation
    {
        return new Reservation($extra + [
            'check_in' => new Date($checkIn),
            'check_out' => new Date($checkOut),
            'reservation_discounts' => [],
            'total_guests' => 1,
            'discount_amount' => null,
            'promo_rate' => null,
        ]);
    }

    /**
     * Senior/PWD beneficiary rows — only their number and type matter to a
     * quote, the name and ID are for the invoice line.
     *
     * @return list<\App\Model\Entity\ReservationDiscount>
     */
    private function beneficiaries(string ...$types): array
    {
        $rows = [];
        foreach ($types as $i => $type) {
            $rows[] = new ReservationDiscount([
                'discount_type' => $type,
                'beneficiary_name' => 'Guest ' . ($i + 1),
                'id_number' => 'ID-' . ($i + 1),
            ]);
        }

        return $rows;
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

    public function testASeniorAloneInTheRoomTakesTwentyPercentOfAllOfIt(): void
    {
        // One beneficiary, one guest: their own share is the whole room, so
        // this is the flat 20% a booking used to get — the case every
        // pre-existing booking was migrated into.
        $booking = $this->booking('2026-03-01', '2026-03-04', [
            'reservation_discounts' => $this->beneficiaries('senior'),
        ]);
        $quote = $this->Reservations->quote($booking, 1500.0);

        $this->assertSame(900.0, $quote['statutory_discount']);
        $this->assertSame(0.0, $quote['referral_discount']);
        $this->assertSame(3600.0, $quote['total']);
    }

    public function testTheDiscountOnlyCoversTheBeneficiarysOwnShare(): void
    {
        // One senior among three guests doesn't discount the other two's
        // share of the room: 20% of a third of 4500, not 20% of 4500.
        $booking = $this->booking('2026-03-01', '2026-03-04', [
            'reservation_discounts' => $this->beneficiaries('senior'),
            'total_guests' => 3,
        ]);
        $quote = $this->Reservations->quote($booking, 1500.0);

        $this->assertSame(300.0, $quote['statutory_discount']);
        $this->assertSame(4200.0, $quote['total']);
    }

    public function testSeveralBeneficiariesEachTakeATheirOwnShare(): void
    {
        // The case the single discount_type flag couldn't express at all: an
        // elderly couple in a room booked for three.
        $booking = $this->booking('2026-03-01', '2026-03-04', [
            'reservation_discounts' => $this->beneficiaries('senior', 'pwd'),
            'total_guests' => 3,
        ]);
        $quote = $this->Reservations->quote($booking, 1500.0);

        $this->assertSame(600.0, $quote['statutory_discount']);
        $this->assertSame([300.0, 300.0], $quote['statutory_shares']);
        $this->assertSame(3900.0, $quote['total']);
    }

    public function testSharesSumToTheTotalWhenTheCentsDontDivideEvenly(): void
    {
        // Each share becomes its own invoice line, so a lost rounding cent
        // would leave the folio disagreeing with the booking's own total.
        $booking = $this->booking('2026-03-01', '2026-03-02', [
            'reservation_discounts' => $this->beneficiaries('senior', 'senior', 'pwd'),
            'total_guests' => 3,
        ]);
        $quote = $this->Reservations->quote($booking, 333.35);

        $this->assertSame(66.67, $quote['statutory_discount']);
        $this->assertSame([22.23, 22.22, 22.22], $quote['statutory_shares']);
        $this->assertSame(66.67, array_sum($quote['statutory_shares']));
    }

    public function testMoreBeneficiariesThanGuestsCannotDiscountMoreThanTheRoom(): void
    {
        // The controller rejects this at the door; the quote caps it too, so a
        // hand-edited row can't price the discount above the statutory rate.
        $booking = $this->booking('2026-03-01', '2026-03-04', [
            'reservation_discounts' => $this->beneficiaries('senior', 'senior', 'pwd'),
            'total_guests' => 1,
        ]);
        $quote = $this->Reservations->quote($booking, 1500.0);

        $this->assertSame(900.0, $quote['statutory_discount']);
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
            'reservation_discounts' => $this->beneficiaries('senior'),
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
            'reservation_discounts' => $this->beneficiaries('pwd'),
            'discount_amount' => 99999.0,
        ]);
        $quote = $this->Reservations->quote($booking, 1500.0);

        $this->assertSame(3600.0, $quote['referral_discount']);
        $this->assertSame(0.0, $quote['total']);
    }

    public function testAPercentageChannelDiscountComesOffTheSubtotal(): void
    {
        $booking = $this->booking('2026-03-01', '2026-03-04', [
            'channel_discount_type' => 'percent',
            'channel_discount_value' => '10.00',
        ]);
        $quote = $this->Reservations->quote($booking, 1500.0);

        $this->assertSame(450.0, $quote['channel_discount']);
        $this->assertSame(450.0, $quote['discount']);
        $this->assertSame(4050.0, $quote['total']);
    }

    public function testAFixedChannelDiscountIsCappedAtTheSubtotal(): void
    {
        $booking = $this->booking('2026-03-01', '2026-03-02', [
            'channel_discount_type' => 'fixed',
            'channel_discount_value' => '5000',
        ]);
        $quote = $this->Reservations->quote($booking, 1500.0);

        $this->assertSame(1500.0, $quote['channel_discount']);
        $this->assertSame(0.0, $quote['total']);
    }

    public function testTheStatutoryDiscountIsAShareOfTheChannelPrice(): void
    {
        // The channel sold the stay at 4,500 − 500 = 4,000; a lone senior
        // takes 20% of that, then the referral comes off what's left.
        $booking = $this->booking('2026-03-01', '2026-03-04', [
            'channel_discount_type' => 'fixed',
            'channel_discount_value' => '500',
            'reservation_discounts' => $this->beneficiaries('senior'),
            'discount_amount' => '200',
        ]);
        $quote = $this->Reservations->quote($booking, 1500.0);

        $this->assertSame(500.0, $quote['channel_discount']);
        $this->assertSame(800.0, $quote['statutory_discount']);
        $this->assertSame(200.0, $quote['referral_discount']);
        $this->assertSame(1500.0, $quote['discount']);
        $this->assertSame(3000.0, $quote['total']);
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
