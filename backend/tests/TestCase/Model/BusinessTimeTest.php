<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model;

use App\Model\BusinessTime;
use Cake\Core\Configure;
use Cake\I18n\DateTime;
use Cake\TestSuite\TestCase;

/**
 * Day boundaries on the hotel's clock.
 *
 * Every "today", daily collection and day filter goes through these, and
 * getting them wrong is silent: money collected before 8 AM in Manila landed
 * on the previous day's report while the cut-offs were computed in UTC.
 */
class BusinessTimeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('App.businessTimezone', 'Asia/Manila');
    }

    public function testADayStartsAtTheHotelsMidnightInStoredTime(): void
    {
        // Midnight in Manila (UTC+8) is 16:00 UTC the day before.
        $this->assertSame('2026-09-28 16:00:00', BusinessTime::startOf('2026-09-29'));
        $this->assertSame('2026-09-29 16:00:00', BusinessTime::endOf('2026-09-29'));
    }

    public function testAnEarlyMorningCollectionFallsOnItsOwnDay(): void
    {
        // Settled at 7 AM on the 29th in Manila = 23:00 UTC on the 28th: it
        // belongs to the 29th's window, not the 28th's.
        $settledAt = DateTime::parse('2026-09-29 07:00:00', 'Asia/Manila')
            ->setTimezone('UTC')->format('Y-m-d H:i:s');

        $this->assertGreaterThanOrEqual(BusinessTime::startOf('2026-09-29'), $settledAt);
        $this->assertLessThan(BusinessTime::endOf('2026-09-29'), $settledAt);
    }

    public function testMovingAStampToAnotherDateKeepsItsLocalTime(): void
    {
        // 2:30 PM in Manila on the 20th, stored as UTC.
        $stamp = DateTime::parse('2026-09-20 14:30:00', 'Asia/Manila')->setTimezone('UTC');

        $moved = BusinessTime::onDate($stamp, '2026-09-18')->setTimezone('Asia/Manila');

        $this->assertSame('2026-09-18 14:30', $moved->format('Y-m-d H:i'));
    }

    public function testAnUnknownTimeOfDayIsTheHotelsMidnight(): void
    {
        $this->assertSame(
            '2026-09-20 00:00',
            BusinessTime::midnightOf('2026-09-20')->setTimezone('Asia/Manila')->format('Y-m-d H:i'),
        );
    }
}
