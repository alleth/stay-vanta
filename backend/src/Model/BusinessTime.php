<?php
declare(strict_types=1);

namespace App\Model;

use Cake\Core\Configure;
use Cake\I18n\Date;
use Cake\I18n\DateTime;

/**
 * The hotel's own clock, for anything that means "today", "this day", "this
 * week" or "this month".
 *
 * Timestamps are stored in the app's default timezone (UTC), but a front desk
 * in Manila closes its day at midnight Manila time — eight hours before UTC
 * does. Computing day boundaries in UTC put everything collected between
 * midnight and 8 AM on the previous day's report, and made the booking form's
 * "today" disagree with the receptionist's. So: dates are decided here, in
 * `App.businessTimezone`, and converted to the stored timezone only when they
 * become query bounds (startOf()), so existing rows need no migration.
 *
 * Date-only columns (check_in, check_out) are already local dates and are
 * compared against today() directly.
 */
final class BusinessTime
{
    public const DEFAULT_TIMEZONE = 'Asia/Manila';

    /**
     * The hotel's timezone name (`App.businessTimezone`).
     */
    public static function timezone(): string
    {
        return (string)(Configure::read('App.businessTimezone') ?: self::DEFAULT_TIMEZONE);
    }

    /**
     * The current moment on the hotel's clock (for startOfWeek() etc.).
     */
    public static function now(): DateTime
    {
        return DateTime::now(self::timezone());
    }

    /**
     * Today's date where the hotel is.
     */
    public static function today(): Date
    {
        return Date::today(self::timezone());
    }

    /**
     * Today's date where the hotel is, as `Y-m-d`.
     */
    public static function todayString(): string
    {
        return self::today()->format('Y-m-d');
    }

    /**
     * The first moment of the hotel's `$ymd`, as a stored-timezone timestamp
     * string ready to compare against a datetime column.
     */
    public static function startOf(string $ymd): string
    {
        return self::stored(DateTime::parse($ymd . ' 00:00:00', self::timezone()));
    }

    /**
     * The first moment of the hotel's day after `$ymd` — the exclusive end of
     * a one-day window.
     */
    public static function endOf(string $ymd): string
    {
        return self::stored(DateTime::parse($ymd . ' 00:00:00', self::timezone())->addDays(1));
    }

    /**
     * The hotel's midnight at the start of `$ymd`, as a DateTime in the stored
     * timezone — for stamping an event whose time of day isn't known (a past
     * stay entered after the fact).
     */
    public static function midnightOf(string $ymd): DateTime
    {
        return DateTime::parse($ymd . ' 00:00:00', self::timezone())->setTimezone(date_default_timezone_get());
    }

    /**
     * `$moment` moved to the hotel's `$ymd`, keeping its local time of day,
     * returned in the stored timezone.
     */
    public static function onDate(DateTime $moment, string $ymd): DateTime
    {
        [$y, $m, $d] = array_map('intval', explode('-', $ymd));

        return $moment->setTimezone(self::timezone())->setDate($y, $m, $d)
            ->setTimezone(date_default_timezone_get());
    }

    /**
     * A moment on the hotel's clock converted to the stored timezone, as a
     * timestamp string.
     */
    public static function stored(DateTime $moment): string
    {
        return $moment->setTimezone(date_default_timezone_get())->format('Y-m-d H:i:s');
    }
}
