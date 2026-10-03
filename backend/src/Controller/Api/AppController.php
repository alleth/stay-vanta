<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Auth\Permissions;
use App\Auth\PermissionSet;
use App\Model\Entity\User;
use App\Model\Table\UsersTable;
use Cake\Controller\Controller;
use Cake\Event\EventInterface;
use Cake\Http\Exception\ForbiddenException;
use Cake\Http\Exception\UnauthorizedException;
use Cake\ORM\Query\SelectQuery;

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
    protected ?User $currentUser = null;

    /**
     * The current user's permissions, loaded once per request by permissions().
     */
    private ?PermissionSet $permissions = null;

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
