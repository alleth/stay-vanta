<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Auth\Access;
use App\Auth\AccessResolver;
use App\Auth\PermissionSet;
use App\Event\EventContext;
use App\Event\ReasonRequiredException;
use App\Middleware\CorrelationIdMiddleware;
use App\Model\Entity\User;
use App\Model\Subscription;
use App\Model\Table\AccessEventsTable;
use App\Model\Table\GuestEventsTable;
use App\Model\Table\UsersTable;
use Cake\Controller\Controller;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\ForbiddenException;
use Cake\Http\Exception\UnauthorizedException;
use Cake\Http\Response;
use Cake\I18n\DateTime;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\Table;
use Cake\Utility\Text;
use DateTimeImmutable;

/**
 * Base controller for all JSON API endpoints.
 *
 * Implements a lightweight bearer-token authentication scheme: a SHA-256
 * digest of the token is stored on the users row (api_token) and the digest of
 * the presented token is matched on each request — the token itself is never
 * persisted. The authenticated user is exposed via $this->currentUser so
 * actions can stamp the acting receptionist for accountability.
 *
 * NOTE: This is a foundation-level scheme. For production, migrate to the
 * cakephp/authentication plugin (JWT or session) — see CLAUDE.md.
 */
class AppController extends Controller
{
    /**
     * How a staff member is embedded in another record (who placed a sale,
     * last touched a reservation or moved stock): id and display name only,
     * never the account's other fields (build step 8).
     */
    protected const USER_BRIEF = ['fields' => ['id', 'name']];

    protected ?User $currentUser = null;

    /**
     * What the current user may do and where, resolved once per request by
     * access() from their membership or the platform flag (step 10b).
     */
    private ?Access $access = null;

    /**
     * This request's event context, built once by eventContext().
     */
    private ?EventContext $eventContext = null;

    /**
     * Actions that do not require a valid token.
     */
    protected array $publicActions = [];

    /**
     * @inheritDoc
     */
    public function initialize(): void
    {
        parent::initialize();
        $this->loadComponent('Flash');
    }

    /**
     * Force JSON and resolve the bearer token to $this->currentUser.
     *
     * @param \Cake\Event\EventInterface $event The beforeFilter event.
     */
    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);

        // Always respond as JSON.
        $this->viewBuilder()->setClassName('Json');
        $this->response = $this->response->withType('application/json');

        if (in_array($this->request->getParam('action'), $this->publicActions, true)) {
            return;
        }

        $this->currentUser = $this->resolveUserFromToken();
        if ($this->currentUser === null) {
            throw new UnauthorizedException('Missing or invalid token.');
        }

        // A support session is fully audited (A6, B7): every request made
        // during it is recorded before it runs. If the record can't be
        // written, the request doesn't happen.
        $session = $this->access()->supportSession;
        if ($session !== null) {
            $this->fetchTable('AccessEvents')->getConnection()->transactional(function () use ($session): void {
                $this->recordAccess($this->eventContext(), AccessEventsTable::SUPPORT_ACCESS_USED, $this->currentUser, [
                    'changes' => [
                        'method' => $this->request->getMethod(),
                        'path' => substr($this->request->getPath(), 0, 255),
                    ],
                    'columns' => ['support_session_id' => (int)$session->get('id')],
                    'propertyId' => (int)$session->get('property_id'),
                ]);
            });
        }
    }

    /**
     * What the current user may do and where (step 10b): their membership's
     * property and its role's grants, or the platform flag. Resolved once.
     */
    protected function access(): Access
    {
        if ($this->access === null) {
            $this->access = $this->currentUser !== null
                ? (new AccessResolver())->resolve($this->currentUser)
                : Access::none();
        }

        return $this->access;
    }

    /**
     * The property the signed-in user belongs to (their membership's), or
     * null on the platform. This answers "is this user tied to one property?"
     * (e.g. may they see other properties' staff), which is a different
     * question from "which property is this request for?" — that's
     * effectivePropertyId().
     */
    protected function boundPropertyId(): ?int
    {
        return $this->access()->platform ? null : $this->access()->propertyId;
    }

    /**
     * The property the current user is scoped to.
     *
     * Property staff are bound to their membership's property (null when they
     * have none); the platform isn't, and may target any property via a
     * `property_id` request param.
     */
    protected function effectivePropertyId(): ?int
    {
        if (!$this->access()->platform) {
            return $this->access()->propertyId;
        }
        $requested = $this->request->getData('property_id') ?? $this->request->getQuery('property_id');

        return $requested !== null ? (int)$requested : null;
    }

    /**
     * Apply the current user's property scope to a query when they are bound
     * to a property. The platform sees everything (optionally filtered by
     * query param); property staff without a membership see nothing.
     */
    protected function scopeToProperty(SelectQuery $query): SelectQuery
    {
        $propertyId = $this->effectivePropertyId();
        if ($propertyId !== null) {
            $query->where([$query->getRepository()->getAlias() . '.property_id' => $propertyId]);
        } elseif (!$this->access()->platform) {
            $query->where(['1 = 0']);
        }

        return $query;
    }

    /**
     * Count the distinct values of a column for a query.
     *
     * NOTE: do NOT use `$query->distinct(['col'])->count()` for this — in
     * CakePHP that emits `GROUP BY col` while still selecting every column, and
     * the count subquery then fails on MySQL's ONLY_FULL_GROUP_BY (the default
     * on MySQL 8 / Railway) with "...id isn't in GROUP BY". This emits a plain
     * `COUNT(DISTINCT col)` instead. `$column` must be a trusted identifier
     * (never user input — it goes into the SQL verbatim).
     */
    protected function countDistinct(SelectQuery $query, string $column): int
    {
        $query->select(['c' => $query->func()->count($query->expr('DISTINCT ' . $column))]);
        $row = $query->disableHydration()->first();

        return (int)($row['c'] ?? 0);
    }

    /**
     * What the current user may do on this request: the grants of their
     * membership's role at its property, or the platform's (step 10b).
     */
    protected function permissions(): PermissionSet
    {
        return $this->access()->permissions;
    }

    /**
     * Whether the current user holds a permission. For checks that depend on
     * the data (e.g. cancelling an order that's already paid); a whole action
     * is gated with authorize().
     */
    protected function can(string $permission): bool
    {
        return $this->permissions()->has($permission);
    }

    /**
     * Refuse the request (403) unless the current user holds the permission.
     * Every API action calls this first, after allowMethod() (deny by
     * default; see docs/PERMISSIONS.md). It answers "may they do this?" only:
     * the property is still scoped with scopeToProperty()/effectivePropertyId().
     *
     * @param string $permission A Permissions constant.
     * @param string|null $message Shown to the user; name roles by their display names.
     */
    protected function authorize(string $permission, ?string $message = null): void
    {
        if (!$this->can($permission)) {
            if ($this->access()->supportSession !== null) {
                throw new ForbiddenException('Support access is read-only.');
            }
            if (in_array($permission, $this->access()->withheld, true)) {
                $this->refuseWhenReadOnly();
            }
            throw new ForbiddenException($message ?? "You don't have permission to do this.");
        }
    }

    /**
     * Like authorize(), for a named wind-down action (A7, B1): checking a
     * guest out, posting a started stay's room charge. While the property is
     * read-only these stay allowed for whoever's role grants the permission
     * (Permissions::WIND_DOWN), though the permission itself is withheld.
     *
     * @param string $permission A Permissions constant.
     */
    protected function authorizeWindDown(string $permission): void
    {
        if (in_array($permission, $this->access()->windDown, true)) {
            return;
        }
        $this->authorize($permission);
    }

    /**
     * Refuse a change because the property's subscription has ended (A7):
     * 403, saying until when it's read-only or that it's suspended. Does
     * nothing while the subscription allows changes. For actions whose
     * permission stays held in read-only but whose purpose doesn't (creating
     * an account, B2).
     */
    protected function refuseWhenReadOnly(): void
    {
        $subscription = $this->access()->subscription;
        if ($subscription === null || !$subscription->isReadOnly()) {
            return;
        }
        if ($subscription->isSuspended()) {
            throw new ForbiddenException(
                "This property's subscription is suspended. Contact the platform to renew it.",
            );
        }
        throw new ForbiddenException(sprintf(
            "This property's subscription has ended, so it is read-only%s. Contact the platform to renew it.",
            $subscription->mode === Subscription::MODE_SUSPEND && $subscription->suspendedFrom() !== null
                ? ' until it is suspended on ' . $subscription->suspendedFrom()->format('M j, Y')
                : '',
        ));
    }

    /**
     * Like authorize(), for an elevated permission (Permissions::ELEVATED):
     * the request must also say why, in `reason`. The reason reaches the
     * ledger through eventContext(), where record() checks it again.
     *
     * @param string $permission A Permissions constant.
     * @param string|null $message Refusal message; name roles by their display names.
     */
    protected function authorizeElevated(string $permission, ?string $message = null): void
    {
        $this->authorize($permission, $message);
        if ($this->eventContext()->reason === null) {
            throw new ReasonRequiredException();
        }
    }

    /**
     * Who is acting in this request, for every ledger write: the user, their
     * role now, the request's property, its correlation id (one per request,
     * from CorrelationIdMiddleware) and the `reason` it sent, if any. Built
     * once, so every event of the request shares the id and the clock.
     */
    protected function eventContext(): EventContext
    {
        if ($this->eventContext === null) {
            $reason = $this->request->getData('reason');
            $this->eventContext = new EventContext(
                $this->currentUser?->id !== null ? (int)$this->currentUser->id : null,
                $this->access()->roleCode ?? $this->currentUser?->role,
                $this->effectivePropertyId(),
                $this->correlationId(),
                EventContext::SOURCE_WEB,
                is_string($reason) ? $reason : null,
            );
        }

        return $this->eventContext;
    }

    /**
     * This request's correlation id (CorrelationIdMiddleware), shared by
     * every event it records.
     */
    protected function correlationId(): string
    {
        return (string)($this->request->getAttribute(CorrelationIdMiddleware::ATTRIBUTE) ?? Text::uuid());
    }

    /**
     * Record an access event (build step 10, access_events) about `$subject`,
     * the person whose access it is, in the caller's transaction.
     * `$withDevice` adds the browser's user agent and the client address the
     * edge reported (sign-ins, A4): kept to recognise a sign-in, never used to
     * allow or refuse anyone.
     *
     * @param array<string, mixed> $options `changes`, `snapshot` (see EventLedgerBehavior::record()).
     */
    protected function recordAccess(
        EventContext $context,
        string $type,
        EntityInterface $subject,
        array $options = [],
        bool $withDevice = false,
    ): void {
        if ($withDevice) {
            $agent = substr($this->request->getHeaderLine('User-Agent'), 0, 255);
            $options['columns'] = [
                'user_agent' => $agent !== '' ? $agent : null,
                'client_address' => $this->reportedClientAddress(),
            ];
        }
        /** @var \App\Model\Table\AccessEventsTable $events */
        $events = $this->fetchTable('AccessEvents');
        $events->record($context, $type, $subject, $options);
    }

    /**
     * One page of a person's access history (step 10), newest first, 25 a
     * page → {events, page, has_more}. Who acted is named (a reset by a
     * Manager, a deactivation); a failed attempt has nobody (`actor` null,
     * `proven` false).
     */
    protected function respondWithAccessHistory(int $userId): void
    {
        $perPage = 25;
        $page = min(200, max(1, (int)($this->request->getQuery('page') ?? 1)));
        $rows = $this->fetchTable('AccessEvents')->find()
            ->where(['subject_user_id' => $userId])
            ->orderBy(['occurred_at' => 'DESC', 'id' => 'DESC'])
            ->limit($perPage + 1)->offset(($page - 1) * $perPage)
            ->all()->toList();
        $hasMore = count($rows) > $perPage;
        $rows = array_slice($rows, 0, $perPage);

        $actorIds = array_values(array_unique(array_filter(array_map(fn($r) => $r->actor_id, $rows))));
        $names = $actorIds === [] ? [] : $this->fetchTable('Users')->find()
            ->select(['id', 'name'])->where(['id IN' => $actorIds])->all()->combine('id', 'name')->toArray();

        $events = array_map(fn($r) => [
            'id' => (int)$r->id,
            'at' => $r->occurred_at,
            'event' => $r->event_type,
            'actor' => $r->actor_id !== null ? ($names[$r->actor_id] ?? null) : null,
            'actor_role' => $r->actor_role,
            'self' => $r->actor_id !== null && (int)$r->actor_id === $userId,
            'proven' => $r->actor_id !== null || $r->source !== 'web',
            'recorded' => $r->source !== 'import',
            'source' => $r->source,
            'reason' => $r->reason,
            'changes' => $r->changes,
            'user_agent' => $r->user_agent,
            'client_address' => $r->client_address,
            // When the retention routine cleared the device details (G5), else null.
            'redacted_at' => $r->redacted_at,
            'scope' => $r->scope,
        ], $rows);

        $this->set(['events' => $events, 'page' => $page, 'has_more' => $hasMore]);
        $this->viewBuilder()->setOption('serialize', ['events', 'page', 'has_more']);
    }

    /**
     * Record a new guest's registration (final review G3), in the caller's
     * transaction: where it came from (`via`: guests | reservation |
     * walk_in), the details it was registered with, and, when look-alike
     * guests already existed, which ones it was created despite. That
     * override needs a reason (GU3; `$context` carries it): the event
     * refuses to record without one, so the guest isn't created either.
     *
     * @param \App\Event\EventContext $context Who, and why for an override.
     * @param \Cake\Datasource\EntityInterface $guest The guest just saved.
     * @param string $via GuestEventsTable::VIA_*.
     * @param list<\Cake\Datasource\EntityInterface> $matches Look-alike guests found.
     */
    protected function recordGuestRegistration(
        EventContext $context,
        EntityInterface $guest,
        string $via,
        array $matches,
    ): void {
        $changes = ['via' => $via, 'after' => GuestEventsTable::detailsOf($guest)];
        if ($matches !== []) {
            $changes['matches'] = array_map(
                fn($m) => ['guest_id' => (int)$m->get('id'), 'name' => $m->get('full_name')],
                $matches,
            );
        }
        /** @var \App\Model\Table\GuestEventsTable $events */
        $events = $this->fetchTable('GuestEvents');
        $events->record(
            $context,
            $matches === [] ? GuestEventsTable::REGISTERED : GuestEventsTable::REGISTERED_DESPITE_MATCHES,
            $guest,
            ['changes' => $changes],
        );
    }

    /** Longest range an export covers, in days (step 10c). */
    protected const EXPORT_MAX_DAYS = 366;

    /** Most rows one export file holds (step 10c); more asks for a shorter range. */
    protected const EXPORT_MAX_ROWS = 50000;

    /**
     * An export's date range from `from` and `to` (YYYY-MM-DD, hotel days,
     * both inclusive), at most EXPORT_MAX_DAYS long. 400 otherwise.
     *
     * @return array{0: string, 1: string} [from, to]
     */
    protected function exportRange(): array
    {
        $range = [];
        foreach (['from', 'to'] as $key) {
            $value = (string)$this->request->getQuery($key);
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if ($date === false || $date->format('Y-m-d') !== $value) {
                throw new BadRequestException("Choose a {$key} date (YYYY-MM-DD).");
            }
            $range[] = $date;
        }
        [$from, $to] = $range;
        if ($to < $from) {
            throw new BadRequestException('The end date must be on or after the start date.');
        }
        if ($from->diff($to)->days + 1 > self::EXPORT_MAX_DAYS) {
            throw new BadRequestException('An export covers at most 12 months. Choose a shorter range.');
        }

        return [$from->format('Y-m-d'), $to->format('Y-m-d')];
    }

    /**
     * Answer with an export (step 10c, X1–X4): a CSV file (UTF-8 with a
     * byte-order mark, so spreadsheets read accents correctly) named after
     * the list and range. The download is recorded first, as `data_exported`
     * (who, which list, which dates, how many rows, from which device; never
     * the rows), in its own transaction: if the record can't be written,
     * nothing is sent.
     *
     * @param string $dataset reservations | guests | invoices | collections.
     * @param array{0: string, 1: string} $range From exportRange().
     * @param list<string> $header Column titles.
     * @param list<list<mixed>> $rows One list of cells per row.
     */
    protected function respondWithCsv(string $dataset, array $range, array $header, array $rows): Response
    {
        if (count($rows) > self::EXPORT_MAX_ROWS) {
            throw new BadRequestException(sprintf(
                'This range has more than %s rows. Choose a shorter range.',
                number_format(self::EXPORT_MAX_ROWS),
            ));
        }
        [$from, $to] = $range;
        $this->fetchTable('AccessEvents')->getConnection()->transactional(
            function () use ($dataset, $from, $to, $rows): void {
                $this->recordAccess($this->eventContext(), AccessEventsTable::DATA_EXPORTED, $this->currentUser, [
                    'changes' => ['dataset' => $dataset, 'from' => $from, 'to' => $to, 'rows' => count($rows)],
                    'propertyId' => $this->effectivePropertyId(),
                ], true);
            },
        );

        $out = fopen('php://temp', 'w+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $header, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($out, array_map([$this, 'csvCell'], $row), ',', '"', '');
        }
        rewind($out);
        $csv = (string)stream_get_contents($out);
        fclose($out);

        return $this->response
            ->withType('csv')
            ->withStringBody($csv)
            ->withDownload(sprintf('%s-%s-to-%s.csv', $dataset, $from, $to));
    }

    /**
     * One CSV cell. Text that a spreadsheet would run as a formula (a guest
     * named "=HYPERLINK(...)") is prefixed with an apostrophe; numbers stay
     * numbers, negative amounts included.
     */
    private function csvCell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }
        if (is_int($value) || is_float($value)) {
            // Rounding a tiny negative gives -0.0, which would print as "-0".
            return (string)($value == 0 ? 0 : $value);
        }
        $text = (string)$value;
        if ($text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) && !is_numeric($text)) {
            return "'" . $text;
        }

        return $text;
    }

    /**
     * The client address as the edge reported it: Railway's `X-Real-IP`,
     * else the last `X-Forwarded-For` hop, else the connection. Informational
     * only: headers can be forged, so nothing is ever allowed or refused on it
     * (the login throttle is keyed on the address typed, for the same reason).
     */
    private function reportedClientAddress(): ?string
    {
        $real = trim($this->request->getHeaderLine('X-Real-IP'));
        if ($real !== '') {
            return substr($real, 0, 64);
        }
        $forwarded = array_filter(array_map('trim', explode(',', $this->request->getHeaderLine('X-Forwarded-For'))));
        $address = $forwarded !== [] ? end($forwarded) : (string)$this->request->getEnv('REMOTE_ADDR');

        return $address !== '' ? substr($address, 0, 64) : null;
    }

    /**
     * Save options for a configuration row (build step 9): who is making
     * the change, for ConfigAuditBehavior. `$refuseNoop` makes a save that
     * changes nothing a 400 ("Nothing to change") that records nothing.
     *
     * @return array<string, mixed>
     */
    protected function auditOptions(bool $refuseNoop = false): array
    {
        return ['eventContext' => $this->eventContext()] + ($refuseNoop ? ['refuseNoop' => true] : []);
    }

    /**
     * Soft-delete a configuration row (build step 9, C3): it stays, hidden
     * from lists, and its `deleted` change keeps every value and the reason.
     */
    protected function softDelete(Table $table, EntityInterface $entity): void
    {
        $entity->set('deleted_at', new DateTime());
        $table->saveOrFail($entity, $this->auditOptions());
    }

    /**
     * True when the current user holds any of the given roles.
     *
     * Not for access checks: use authorize()/can(). Kept only for the rules
     * that stay role-based (who may manage whom, in UsersController).
     */
    protected function userHasRole(string ...$roles): bool
    {
        return in_array($this->currentUser?->role, $roles, true);
    }

    /**
     * Pull the bearer token from the Authorization header and load the user.
     */
    protected function resolveUserFromToken(): ?User
    {
        $header = $this->request->getHeaderLine('Authorization');
        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            return null;
        }

        // Match on the digest: `users.api_token` stores the hash, never the
        // token itself (see UsersTable::hashToken).
        $users = $this->fetchTable('Users');
        $user = $users->find()
            ->where([
                'api_token' => UsersTable::hashToken($m[1]),
                'is_active' => true,
            ])
            ->first();

        if ($user === null) {
            return null;
        }

        if ($user->token_expires !== null && $user->token_expires->isPast()) {
            return null;
        }

        return $user;
    }
}
