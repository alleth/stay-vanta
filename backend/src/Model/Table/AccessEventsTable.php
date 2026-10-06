<?php
declare(strict_types=1);

namespace App\Model\Table;

use ArrayObject;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Cake\ORM\Table;

/**
 * Access ledger (build step 10, approved 2026-10-05): every change to who can
 * get in, and every sign-in. The subject is the person whose access it is
 * (`subject_user_id`); the actor is whoever acted (a Manager deactivating
 * someone, the person signing in). A sign-in attempt that failed proved
 * nobody, so it has no actor.
 *
 * Platform events (the Platform Owner's own account and sign-ins) have no
 * property: `scope = platform` (A2), and they never enter a hotel's
 * activity feed. Sign-ins are never indexed for the feed either (A9); the
 * account changes in FEED_TYPES are.
 */
class AccessEventsTable extends Table
{
    use AppendOnlyTableTrait;
    use EventLedgerTableTrait;

    // Accounts
    /** An account is made (on screen, or by the create_user command as `system`). */
    public const ACCOUNT_CREATED = 'account_created';
    /** Its name changes. */
    public const ACCOUNT_RENAMED = 'account_renamed';
    /** It's switched off; its session ends with it. Needs a reason. */
    public const ACCOUNT_DEACTIVATED = 'account_deactivated';
    /** It's switched back on (no old session comes back). Needs a reason. */
    public const ACCOUNT_REACTIVATED = 'account_reactivated';
    /** Someone sets another person's password; their session ends. Needs a reason. */
    public const PASSWORD_RESET = 'password_reset';
    /** A person changes their own password, giving their current one. */
    public const PASSWORD_CHANGED = 'password_changed';
    // Sessions
    /** A successful sign-in (`changes.ended_other_session` when it replaced one). */
    public const SIGNED_IN = 'signed_in';
    /** A wrong password for an existing account (no actor: nobody was proven). */
    public const SIGN_IN_FAILED = 'sign_in_failed';
    /** Repeated failures paused the address. */
    public const SIGN_IN_LOCKED = 'sign_in_locked';
    /** The right password, but the account is inactive. */
    public const SIGN_IN_REFUSED = 'sign_in_refused';
    /** The person signed out. */
    public const SIGNED_OUT = 'signed_out';
    /** A deactivation or password change ended the person's session (same request). */
    public const SESSION_ENDED = 'session_ended';
    // Memberships (Permissions Phase 2, step 10b)
    /** A person gets a role at a property (a new account, or added to a property). */
    public const MEMBERSHIP_GRANTED = 'membership_granted';
    /** Import: a membership made from the account's own role and property. */
    public const MEMBERSHIP_IMPORTED = 'membership_imported';
    /** Their role at the property changes (Platform Owner only); their session ends. Needs a reason. */
    public const MEMBERSHIP_ROLE_CHANGED = 'membership_role_changed';
    // Platform
    /** The platform flag is set (for today's Platform Owner, by the import). */
    public const PLATFORM_ACCESS_GRANTED = 'platform_access_granted';
    /** The Platform Owner opens a read-only support session at one property (A6). Needs a reason. */
    public const SUPPORT_ACCESS_STARTED = 'support_access_started';
    /** One request made during a support session (method and path). */
    public const SUPPORT_ACCESS_USED = 'support_access_used';
    /** The Platform Owner or the property's Manager ends a session before it expires. */
    public const SUPPORT_ACCESS_ENDED = 'support_access_ended';
    // Data leaving the system (step 10c)
    /** A Manager downloads a CSV export: which list, which dates, how many rows. Never the rows. */
    public const DATA_EXPORTED = 'data_exported';

    public const TYPES = [
        self::ACCOUNT_CREATED,
        self::ACCOUNT_RENAMED,
        self::ACCOUNT_DEACTIVATED,
        self::ACCOUNT_REACTIVATED,
        self::PASSWORD_RESET,
        self::PASSWORD_CHANGED,
        self::SIGNED_IN,
        self::SIGN_IN_FAILED,
        self::SIGN_IN_LOCKED,
        self::SIGN_IN_REFUSED,
        self::SIGNED_OUT,
        self::SESSION_ENDED,
        self::MEMBERSHIP_GRANTED,
        self::MEMBERSHIP_IMPORTED,
        self::MEMBERSHIP_ROLE_CHANGED,
        self::PLATFORM_ACCESS_GRANTED,
        self::SUPPORT_ACCESS_STARTED,
        self::SUPPORT_ACCESS_USED,
        self::SUPPORT_ACCESS_ENDED,
        self::DATA_EXPORTED,
    ];

    public const REQUIRES_REASON = [
        self::ACCOUNT_DEACTIVATED,
        self::ACCOUNT_REACTIVATED,
        self::PASSWORD_RESET,
        self::MEMBERSHIP_ROLE_CHANGED,
        self::SUPPORT_ACCESS_STARTED,
    ];

    public const REASON_GRACE = [];

    /**
     * Account administration and support access: these join Operations →
     * Activity (A9). Sign-ins and each request of a support session don't.
     */
    public const FEED_TYPES = [
        self::ACCOUNT_CREATED,
        self::ACCOUNT_DEACTIVATED,
        self::ACCOUNT_REACTIVATED,
        self::PASSWORD_RESET,
        self::MEMBERSHIP_ROLE_CHANGED,
        self::SUPPORT_ACCESS_STARTED,
        self::SUPPORT_ACCESS_ENDED,
        self::DATA_EXPORTED,
    ];

    public const SCOPE_PROPERTY = 'property';
    public const SCOPE_PLATFORM = 'platform';

    /**
     * @param array<string, mixed> $config Table config.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('access_events');
        $this->addBehavior('EventLedger', [
            'subjectKey' => 'subject_user_id',
            'subjectType' => 'user',
            'platformEvents' => true,
            'indexTypes' => self::FEED_TYPES,
        ]);
    }

    /**
     * The scope follows the property: none means a platform event.
     *
     * @param \Cake\Event\EventInterface<\Cake\ORM\Table> $event The event.
     * @param \Cake\Datasource\EntityInterface $entity The new row.
     * @param \ArrayObject<string, mixed> $options Save options.
     * @return void
     */
    public function beforeSave(EventInterface $event, EntityInterface $entity, ArrayObject $options): void
    {
        if ($entity->isNew()) {
            $entity->set('scope', $entity->get('property_id') === null ? self::SCOPE_PLATFORM : self::SCOPE_PROPERTY);
        }
    }

    /**
     * Who the event is about, as they were: name and role, never contact details.
     *
     * @param \Cake\Datasource\EntityInterface $subject The user.
     * @return array<string, mixed>
     */
    public function snapshotOf(EntityInterface $subject): array
    {
        return ['name' => $subject->get('name'), 'role' => $subject->get('role')];
    }
}
