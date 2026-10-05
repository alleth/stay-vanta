<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Auth\Permissions;
use App\Auth\PermissionSet;
use App\Event\EventContext;
use App\Event\ReasonRequiredException;
use App\Middleware\CorrelationIdMiddleware;
use App\Model\Entity\User;
use App\Model\Table\UsersTable;
use Cake\Controller\Controller;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Cake\Http\Exception\ForbiddenException;
use Cake\Http\Exception\UnauthorizedException;
use Cake\I18n\DateTime;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\Table;
use Cake\Utility\Text;

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
     * The current user's permissions, loaded once per request by permissions().
     */
    private ?PermissionSet $permissions = null;

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
    }

    /**
     * The property the signed-in user belongs to, or null for the Platform
     * Owner. This answers "is this user tied to one property?" (e.g. may they
     * see other properties' staff), which is a different question from "which
     * property is this request for?" — that's effectivePropertyId(). Permissions
     * Phase 2 replaces the users.property_id read here with the membership.
     */
    protected function boundPropertyId(): ?int
    {
        return $this->currentUser?->property_id !== null ? (int)$this->currentUser->property_id : null;
    }

    /**
     * The property the current user is scoped to.
     *
     * Admins/receptionists are bound to their own property; owners aren't
     * (null) and may target any property via a `property_id` request param.
     */
    protected function effectivePropertyId(): ?int
    {
        if ($this->currentUser?->property_id !== null) {
            return (int)$this->currentUser->property_id;
        }
        $requested = $this->request->getData('property_id') ?? $this->request->getQuery('property_id');

        return $requested !== null ? (int)$requested : null;
    }

    /**
     * Apply the current user's property scope to a query when they are bound
     * to a property. Owners see everything (optionally filtered by query param).
     */
    protected function scopeToProperty(SelectQuery $query): SelectQuery
    {
        $propertyId = $this->effectivePropertyId();
        if ($propertyId !== null) {
            $query->where([$query->getRepository()->getAlias() . '.property_id' => $propertyId]);
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
     * What the current user may do on this request. Phase 1 reads it from the
     * user's role; Phase 2 reads the grants of their membership at the
     * request's property, and only this method changes.
     */
    protected function permissions(): PermissionSet
    {
        return $this->permissions ??= Permissions::forRole($this->currentUser?->role);
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
            throw new ForbiddenException($message ?? "You don't have permission to do this.");
        }
    }

    /**
     * Like authorize(), for an elevated permission (Permissions::ELEVATED):
     * the request must also say why, in `reason`. The reason reaches the
     * ledger through eventContext(), where record() checks it again.
     *
     * @param string $permission A Permissions constant.
     * @param string|null $message Refusal message; name roles by their display names.
     * @param bool $reasonOptional Only for a compatibility window, while a
     *   released frontend can't send a reason yet (the ledger's REASON_GRACE).
     */
    protected function authorizeElevated(
        string $permission,
        ?string $message = null,
        bool $reasonOptional = false,
    ): void {
        $this->authorize($permission, $message);
        if (!$reasonOptional && $this->eventContext()->reason === null) {
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
                $this->currentUser?->role,
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
            'scope' => $r->scope,
        ], $rows);

        $this->set(['events' => $events, 'page' => $page, 'has_more' => $hasMore]);
        $this->viewBuilder()->setOption('serialize', ['events', 'page', 'has_more']);
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
