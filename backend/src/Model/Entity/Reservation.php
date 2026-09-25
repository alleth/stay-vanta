<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * Reservation entity.
 *
 * `receptionist_id` is the LAST receptionist to act on the booking (created it
 * or performed a check-in/out/cancel) — the accountability stamp for the room.
 *
 * @property int $id
 * @property int $property_id
 * @property int|null $room_id
 * @property int|null $guest_id
 * @property int|null $receptionist_id
 * @property \Cake\I18n\Date|null $check_in
 * @property \Cake\I18n\Date|null $check_out
 * @property \Cake\I18n\DateTime|null $checked_in_at   when check-in actually happened
 * @property \Cake\I18n\DateTime|null $checked_out_at  when check-out actually happened
 * @property \Cake\I18n\DateTime|null $cancelled_at    when the booking was cancelled
 * @property string $status         booked | checked_in | checked_out | cancelled
 * @property string $source         walk_in, or one of the property's booking_sources codes
 * @property string|null $promo_rate
 * @property string|null $downpayment  50% collected up front on an advance booking
 * @property \App\Model\Entity\ReservationDiscount[] $reservation_discounts  one row per
 *   Senior/PWD guest on this booking; the statutory 20% covers each one's own share of
 *   the room (see ReservationsTable::quote()), so several can qualify at once
 * @property int $total_guests  how many people the room is billed between — the divisor
 *   the statutory discount is shared over
 * @property string|null $channel_discount_type  percent | fixed — a discount the booking
 *   channel promised the guest; null for none, and always null for a walk-in
 * @property string|null $channel_discount_value  the percentage (0-100] or the peso amount
 * @property string|null $discount_amount  flat referral discount amount, independent of
 *   and stackable with the statutory one (a guest can be senior/pwd *and* referred)
 * @property string $payment_status unpaid | paid — Front Desk operational flag,
 *   independent of the booking lifecycle and of invoice settlement
 * @property int $additional_beds
 */
class Reservation extends Entity
{
    protected array $_accessible = [
        'property_id' => true,
        'room_id' => true,
        'guest_id' => true,
        'receptionist_id' => true,
        'check_in' => true,
        'check_out' => true,
        'checked_in_at' => true,
        'checked_out_at' => true,
        'cancelled_at' => true,
        'status' => true,
        'source' => true,
        'booking_reference' => true,
        'promo_rate' => true,
        'sold_rate' => true,
        'channel_discount_type' => true,
        'channel_discount_value' => true,
        'downpayment' => true,
        'total_guests' => true,
        'discount_amount' => true,
        'payment_status' => true,
        'additional_beds' => true,
    ];
}
