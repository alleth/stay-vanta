<?php
declare(strict_types=1);

namespace App\Controller\Api;

use Cake\Controller\Controller;
use Cake\Event\EventInterface;
use Cake\Http\Exception\UnauthorizedException;

/**
 * Base controller for all JSON API endpoints.
 *
 * Implements a lightweight bearer-token authentication scheme: the token is
 * stored on the users row (api_token) and matched on each request. The
 * authenticated user is exposed via $this->currentUser so actions can stamp
 * the acting receptionist for accountability.
 *
 * NOTE: This is a foundation-level scheme. For production, migrate to the
 * cakephp/authentication plugin (JWT or session) — see CLAUDE.md.
 */
class AppController extends Controller
{
    protected ?\App\Model\Entity\User $currentUser = null;

    /** Actions that do not require a valid token. */
    protected array $publicActions = [];

    public function initialize(): void
    {
        parent::initialize();
        $this->loadComponent('Flash');
    }

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
    protected function scopeToProperty(\Cake\ORM\Query\SelectQuery $query): \Cake\ORM\Query\SelectQuery
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
    protected function countDistinct(\Cake\ORM\Query\SelectQuery $query, string $column): int
    {
        $query->select(['c' => $query->func()->count($query->expr('DISTINCT ' . $column))]);
        $row = $query->disableHydration()->first();

        return (int)($row['c'] ?? 0);
    }

    /**
     * True when the current user holds any of the given roles.
     */
    protected function userHasRole(string ...$roles): bool
    {
        return in_array($this->currentUser?->role, $roles, true);
    }

    /**
     * Pull the bearer token from the Authorization header and load the user.
     */
    protected function resolveUserFromToken(): ?\App\Model\Entity\User
    {
        $header = $this->request->getHeaderLine('Authorization');
        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            return null;
        }

        $users = $this->fetchTable('Users');
        $user = $users->find()
            ->where(['api_token' => $m[1], 'is_active' => true])
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
