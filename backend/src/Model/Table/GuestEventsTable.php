<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\Datasource\EntityInterface;
use Cake\ORM\Table;

/**
 * Guest ledger (final review G3, approved 2026-10-09 as GU1–GU5): who
 * registered each guest and from where, every change to their details with
 * before and after, every rename with its reason, and every look-alike
 * override with the matches it overrode. Written by GuestsController and by
 * a booking (ReservationsController) in the change's own transaction, under a
 * lock on the guest row. Only Managers read it (`guests.guest.view_history`).
 *
 * Before/after values include contact details (GU5): they fall under the
 * retention routine when it's built (G5), which clears values, never events.
 */
class GuestEventsTable extends Table
{
    use AppendOnlyTableTrait;
    use EventLedgerTableTrait;

    /** A new guest; `changes.via`: guests | reservation | walk_in, `changes.after`: the details. */
    public const REGISTERED = 'registered';
    /** A new guest although look-alike guests exist (`changes.matches`). Needs a reason (GU3). */
    public const REGISTERED_DESPITE_MATCHES = 'registered_despite_matches';
    /** Nationality, address, phone, email or guest type changed (`changes`: field => before/after). */
    public const DETAILS_UPDATED = 'details_updated';
    /** The full name changed. Needs a reason (GU2): past invoices show the guest's current name. */
    public const RENAMED = 'renamed';
    /** A booking filled in empty fields on a returning guest (`changes`: field => after); never overwrites. */
    public const DETAILS_COMPLETED = 'details_completed';
    /** Import: the guest's registration from before G3, at its own `created`, with no actor. */
    public const IMPORTED = 'imported';

    public const TYPES = [
        self::REGISTERED,
        self::REGISTERED_DESPITE_MATCHES,
        self::DETAILS_UPDATED,
        self::RENAMED,
        self::DETAILS_COMPLETED,
        self::IMPORTED,
    ];

    public const REQUIRES_REASON = [
        self::REGISTERED_DESPITE_MATCHES,
        self::RENAMED,
    ];

    public const REASON_GRACE = [];

    /** Only these reach Operations → Activity (GU4); the rest stay in the guest's history. */
    public const FEED_TYPES = [
        self::REGISTERED_DESPITE_MATCHES,
        self::RENAMED,
    ];

    /** The details a guest record holds, all audited. */
    public const FIELDS = ['full_name', 'nationality', 'address', 'contact_number', 'email', 'guest_type'];

    /** Where a guest was registered from. */
    public const VIA_GUESTS = 'guests';
    public const VIA_RESERVATION = 'reservation';
    public const VIA_WALK_IN = 'walk_in';

    /**
     * @param array<string, mixed> $config Table config.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('guest_events');
        $this->addBehavior('EventLedger', [
            'subjectKey' => 'guest_id',
            'subjectType' => 'guest',
            'indexTypes' => self::FEED_TYPES,
        ]);
    }

    /**
     * The guest as they stood: id and display name only (step 5's minimal
     * snapshot), never contact details.
     *
     * @param \Cake\Datasource\EntityInterface $guest A guest.
     * @return array<string, mixed>
     */
    public function snapshotOf(EntityInterface $guest): array
    {
        return ['guest_id' => (int)$guest->get('id'), 'name' => $guest->get('full_name')];
    }

    /**
     * A guest's details as recorded in an event.
     *
     * @param \Cake\Datasource\EntityInterface $guest A guest.
     * @return array<string, mixed>
     */
    public static function detailsOf(EntityInterface $guest): array
    {
        $values = [];
        foreach (self::FIELDS as $field) {
            $value = $guest->get($field);
            $values[$field] = $value === '' ? null : $value;
        }

        return $values;
    }

    /**
     * Each field that differs between two sets of details: field => before/after.
     *
     * @param array<string, mixed> $before Details before.
     * @param array<string, mixed> $after Details after.
     * @return array<string, array{before: mixed, after: mixed}>
     */
    public static function diff(array $before, array $after): array
    {
        $changes = [];
        foreach (self::FIELDS as $field) {
            $old = $before[$field] ?? null;
            $new = $after[$field] ?? null;
            if ((string)$old !== (string)$new) {
                $changes[$field] = ['before' => $old, 'after' => $new];
            }
        }

        return $changes;
    }
}
