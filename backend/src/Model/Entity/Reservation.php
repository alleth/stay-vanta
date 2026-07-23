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
 * @property string $source         walk_in | cocotel | agoda | trip_com | tripadvisor
 * @property string|null $promo_rate
 * @property string|null $downpayment  50% collected up front on an advance booking
 * @property string $discount_type  none | senior | pwd | referral
 * @property string|null $discount_amount  flat amount for a `referral` discount only
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
        'promo_rate' => true,
        'downpayment' => true,
        'discount_type' => true,
        'discount_amount' => true,
        'payment_status' => true,
        'additional_beds' => true,
    ];
}
