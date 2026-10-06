<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Auth\Permissions;
use App\Model\Entity\User;
use App\Model\Table\AccessEventsTable;
use App\Model\Table\SupportSessionsTable;
use Cake\Datasource\EntityInterface;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\NotFoundException;

/**
 * Support access (build step 10b, A6, approved 2026-10-05/06): the Platform
 * Owner reads one property's data through a support session, read-only, with
 * a reason, for 60 minutes. Every request made during it is recorded
 * (AppController::beforeFilter, `support_access_used`), and the property's
 * Manager sees each session and may end it.
 *
 * Starting and ending lock first (the Platform Owner's user row, or the
 * session row), check against what's locked, change, and record, in one
 * transaction: a second identical request is refused and records nothing.
 */
class SupportSessionsController extends AppController
{
    /**
     * POST /api/platform/support-sessions { property_id, reason } → { session }
     */
    public function start(): void
    {
        $this->request->allowMethod('post');
        $this->authorizeElevated(
            Permissions::PLATFORM_SUPPORT_ACCESS_START,
            'Only the Platform Owner can start support access.',
        );
        if ($this->access()->supportSession !== null) {
            throw new BadRequestException('End the current support session before starting another.');
        }
        $propertyId = $this->effectivePropertyId();
        if ($propertyId === null) {
            throw new BadRequestException('property_id is required.');
        }
        $property = $this->fetchTable('Properties')->find()->where(['id' => $propertyId])->first();
        if ($property === null) {
            throw new NotFoundException('No such property.');
        }

        $sessions = $this->fetchTable('SupportSessions');
        $session = $sessions->getConnection()->transactional(function () use ($sessions, $propertyId): EntityInterface {
            $owner = $this->lockUser((int)$this->currentUser->id);
            if ($sessions->find('open')->where(['SupportSessions.user_id' => $owner->id])->count() > 0) {
                throw new BadRequestException('A support session is already open.');
            }
            $now = $this->eventContext()->now;
            $session = $sessions->saveOrFail($sessions->newEntity([
                'user_id' => (int)$owner->id,
                'property_id' => $propertyId,
                'reason' => (string)$this->eventContext()->reason,
                'started_at' => $now,
                'expires_at' => $now->modify('+' . SupportSessionsTable::SUPPORT_MINUTES . ' minutes'),
            ], ['accessibleFields' => ['*' => true]]), ['atomic' => false]);
            $this->recordAccess($this->eventContext(), AccessEventsTable::SUPPORT_ACCESS_STARTED, $owner, [
                'changes' => ['after' => [
                    'property_id' => $propertyId,
                    'expires_at' => $session->get('expires_at')->toIso8601String(),
                ]],
                'columns' => ['support_session_id' => (int)$session->id],
                'propertyId' => $propertyId,
            ]);

            return $session;
        });

        $this->response = $this->response->withStatus(201);
        $this->set('session', $this->sessionOut($session, ['name' => $property->get('name')]));
        $this->viewBuilder()->setOption('serialize', ['session']);
    }

    /**
     * POST /api/platform/support-sessions/{id}/end [{ reason }] → { session }
     *
     * The Platform Owner ends their own session.
     */
    public function endOwn(int $id): void
    {
        $this->request->allowMethod('post');
        $this->authorize(
            Permissions::PLATFORM_SUPPORT_ACCESS_START,
            'Only the Platform Owner can end their support session.',
        );
        $this->end($id, ['SupportSessions.user_id' => (int)$this->currentUser->id]);
    }

    /**
     * POST /api/support-sessions/{id}/end [{ reason }] → { session }
     *
     * The property's Manager ends a support session at their property.
     */
    public function endAtProperty(int $id): void
    {
        $this->request->allowMethod('post');
        $this->authorize(Permissions::STAFF_ACCOUNT_MANAGE, 'Only Managers can end support access.');
        $this->end($id, ['SupportSessions.property_id' => (int)$this->effectivePropertyId()]);
    }

    /**
     * GET /api/support-sessions[?page=] → { sessions, page, has_more }
     *
     * The property's support sessions, newest first: who, why, when, and how
     * many requests each made.
     */
    public function index(): void
    {
        $this->request->allowMethod('get');
        $this->authorize(Permissions::STAFF_ACCESS_HISTORY_VIEW, 'Only Managers can see support access.');
        $perPage = 25;
        $page = min(200, max(1, (int)($this->request->getQuery('page') ?? 1)));
        $rows = $this->scopeToProperty($this->fetchTable('SupportSessions')->find())
            ->contain(['Users' => self::USER_BRIEF, 'EndedBy' => self::USER_BRIEF])
            ->orderBy(['SupportSessions.started_at' => 'DESC', 'SupportSessions.id' => 'DESC'])
            ->limit($perPage + 1)->offset(($page - 1) * $perPage)
            ->all()->toList();
        $hasMore = count($rows) > $perPage;
        $rows = array_slice($rows, 0, $perPage);

        $ids = array_map(fn($r) => (int)$r->id, $rows);
        $counts = $ids === [] ? [] : $this->fetchTable('AccessEvents')->find()
            ->select(['support_session_id', 'n' => 'COUNT(*)'])
            ->where(['support_session_id IN' => $ids, 'event_type' => AccessEventsTable::SUPPORT_ACCESS_USED])
            ->groupBy(['support_session_id'])
            ->disableHydration()->all()->combine('support_session_id', 'n')->toArray();

        $this->set([
            'sessions' => array_map(
                fn($r) => $this->sessionOut($r) + ['requests' => (int)($counts[$r->id] ?? 0)],
                $rows,
            ),
            'page' => $page,
            'has_more' => $hasMore,
        ]);
        $this->viewBuilder()->setOption('serialize', ['sessions', 'page', 'has_more']);
    }

    /**
     * GET /api/support-sessions/{id} → { session, requests }
     *
     * One session and every request made during it (method, path, when).
     */
    public function view(int $id): void
    {
        $this->request->allowMethod('get');
        $this->authorize(Permissions::STAFF_ACCESS_HISTORY_VIEW, 'Only Managers can see support access.');
        $session = $this->scopeToProperty($this->fetchTable('SupportSessions')->find())
            ->contain(['Users' => self::USER_BRIEF, 'EndedBy' => self::USER_BRIEF])
            ->where(['SupportSessions.id' => $id])
            ->first();
        if ($session === null) {
            throw new NotFoundException('No such support session.');
        }
        $requests = $this->fetchTable('AccessEvents')->find()
            ->select(['id', 'occurred_at', 'changes'])
            ->where(['support_session_id' => $id, 'event_type' => AccessEventsTable::SUPPORT_ACCESS_USED])
            ->orderBy(['id' => 'ASC'])
            ->limit(1000)
            ->all()
            ->map(fn($e) => [
                'at' => $e->occurred_at,
                'method' => $e->changes['method'] ?? null,
                'path' => $e->changes['path'] ?? null,
            ])
            ->toList();

        $this->set(['session' => $this->sessionOut($session), 'requests' => $requests]);
        $this->viewBuilder()->setOption('serialize', ['session', 'requests']);
    }

    /**
     * End one open session matching `$where`: lock it, check it's still open,
     * end it, record who ended it (and why, when they said).
     *
     * @param array<string, mixed> $where Who may end which session.
     */
    private function end(int $id, array $where): void
    {
        $sessions = $this->fetchTable('SupportSessions');
        $session = $sessions->getConnection()->transactional(function () use ($sessions, $id, $where): EntityInterface {
            $session = $sessions->find()
                ->where(['SupportSessions.id' => $id] + $where)
                ->epilog('FOR UPDATE')
                ->first();
            if ($session === null) {
                throw new NotFoundException('No such support session.');
            }
            if (SupportSessionsTable::stateOf($session) !== 'open') {
                throw new BadRequestException('This support session has already ended.');
            }
            $session->set('ended_at', $this->eventContext()->now);
            $session->set('ended_by', (int)$this->currentUser->id);
            $sessions->saveOrFail($session, ['atomic' => false]);
            /** @var \App\Model\Entity\User $owner */
            $owner = $this->fetchTable('Users')->get($session->get('user_id'));
            $this->recordAccess($this->eventContext(), AccessEventsTable::SUPPORT_ACCESS_ENDED, $owner, [
                'changes' => ['ended_by' => (int)$session->get('user_id') === (int)$this->currentUser->id
                    ? 'platform_owner'
                    : 'manager'],
                'columns' => ['support_session_id' => (int)$session->id],
                'propertyId' => (int)$session->get('property_id'),
            ]);

            return $session;
        });

        $this->set('session', $this->sessionOut($session));
        $this->viewBuilder()->setOption('serialize', ['session']);
    }

    /**
     * Re-read a user inside the transaction with a FOR UPDATE lock.
     */
    private function lockUser(int $id): User
    {
        /** @var \App\Model\Entity\User $user */
        $user = $this->fetchTable('Users')->find()->where(['Users.id' => $id])->epilog('FOR UPDATE')->firstOrFail();

        return $user;
    }

    /**
     * A session as the screens see it.
     *
     * @param \Cake\Datasource\EntityInterface $session The session.
     * @param array<string, mixed>|null $property The property's name, when known.
     * @return array<string, mixed>
     */
    private function sessionOut(EntityInterface $session, ?array $property = null): array
    {
        return [
            'id' => (int)$session->id,
            'property_id' => (int)$session->get('property_id'),
            'property_name' => $property['name'] ?? null,
            'by' => $session->get('user')?->get('name'),
            'reason' => $session->get('reason'),
            'started_at' => $session->get('started_at'),
            'expires_at' => $session->get('expires_at'),
            'ended_at' => $session->get('ended_at'),
            'ended_by' => $session->get('ended_by_user')?->get('name'),
            'state' => SupportSessionsTable::stateOf($session),
        ];
    }
}
