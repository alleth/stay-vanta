<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Auth\Permissions;
use App\Model\BusinessTime;
use App\Model\Table\GuestEventsTable;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Response;

/**
 * Guests — registry plus the local/foreign monitoring counts.
 */
class GuestsController extends AppController
{
    /**
     * GET /api/guests[?guest_type=local|foreign][?q=name][?page=&limit=]
     *
     * Paginated (the registry only grows). `total`/`page`/`limit` are always
     * returned, but `limit` only enforces the 5-100 window a caller opts into
     * by passing it explicitly — callers that don't (the Food & Orders
     * charge-to-room picker, the Front Desk booking combobox, both of which
     * want a wide unpaginated list to search over client-side) get the same
     * wide window the endpoint always returned before pagination existed.
     */
    public function index(): void
    {
        $this->authorize(Permissions::GUESTS_GUEST_VIEW);
        $guests = $this->fetchTable('Guests');
        $query = $this->scopeToProperty(
            $guests->find()->orderBy(['Guests.created' => 'DESC']),
        );

        $type = $this->request->getQuery('guest_type');
        if ($type !== null) {
            $query->where(['Guests.guest_type' => $type]);
        }

        $search = trim((string)$this->request->getQuery('q'));
        if ($search !== '') {
            $query->where(['Guests.full_name LIKE' => '%' . $search . '%']);
        }

        $total = $query->count();
        $requestedLimit = $this->request->getQuery('limit');
        $limit = $requestedLimit !== null ? min(100, max(5, (int)$requestedLimit)) : 500;
        $page = max(1, (int)($this->request->getQuery('page') ?? 1));
        $query->limit($limit)->offset(($page - 1) * $limit);

        $this->set([
            'guests' => $query->all(),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ]);
        $this->viewBuilder()->setOption('serialize', ['guests', 'total', 'page', 'limit']);
    }

    /**
     * GET /api/guests/export?from=YYYY-MM-DD&to=YYYY-MM-DD → CSV
     *
     * Guests registered in the range (step 10c, Manager), with their stays.
     * Contact details are included; government ID numbers never are (X3;
     * guests carry none, and the Senior/PWD ID numbers on discounts stay out).
     * Recorded as `data_exported`.
     */
    public function export(): Response
    {
        $this->request->allowMethod('get');
        $this->authorize(Permissions::GUESTS_GUEST_EXPORT, 'Only Managers can export guests.');
        $range = $this->exportRange();
        $guests = $this->scopeToProperty($this->fetchTable('Guests')->find())
            ->select(['id', 'full_name', 'guest_type', 'nationality', 'contact_number', 'email', 'created'])
            ->where([
                'Guests.created >=' => BusinessTime::startOf($range[0]),
                'Guests.created <' => BusinessTime::endOf($range[1]),
            ])
            ->orderBy(['Guests.created' => 'ASC', 'Guests.id' => 'ASC'])
            ->limit(self::EXPORT_MAX_ROWS + 1)
            ->all()->toList();

        // Stays per guest (cancelled ones aren't stays): one grouped query,
        // selecting only the group column and aggregates (ONLY_FULL_GROUP_BY).
        $ids = array_map(fn($g) => (int)$g->id, $guests);
        $stays = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            $query = $this->fetchTable('Reservations')->find();
            $found = $query
                ->select([
                    'guest_id',
                    'stays' => $query->func()->count('*'),
                    'first' => $query->func()->min('check_in'),
                    'last' => $query->func()->max('check_in'),
                ])
                ->where(['guest_id IN' => $chunk, 'status !=' => 'cancelled'])
                ->groupBy(['guest_id'])
                ->disableHydration()
                ->all();
            foreach ($found as $row) {
                $stays[(int)$row['guest_id']] = $row;
            }
        }

        $cells = array_map(function ($g) use ($stays): array {
            $s = $stays[(int)$g->id] ?? null;

            return [
                $g->full_name,
                $g->guest_type,
                $g->nationality,
                $g->contact_number,
                $g->email,
                $g->created?->setTimezone(BusinessTime::timezone())->format('Y-m-d'),
                (int)($s['stays'] ?? 0),
                $s !== null ? substr((string)$s['first'], 0, 10) : null,
                $s !== null ? substr((string)$s['last'], 0, 10) : null,
            ];
        }, $guests);

        return $this->respondWithCsv('guests', $range, [
            'Name', 'Type', 'Nationality', 'Phone', 'Email', 'Registered', 'Stays', 'First stay', 'Last stay',
        ], $cells);
    }

    /**
     * GET /api/guests/stats — counts for the guests dashboard.
     * { total, local, foreign, in_house }
     *
     * total/local/foreign are *today's* registrations (they reset to 0 each
     * day for a fresh-start view); in_house is whoever is checked in right now.
     */
    public function stats(): void
    {
        $this->authorize(Permissions::GUESTS_GUEST_VIEW);
        $guests = $this->fetchTable('Guests');
        // The hotel's midnight, not UTC's (see BusinessTime).
        $startOfToday = BusinessTime::startOf(BusinessTime::todayString());
        $base = fn () => $this->scopeToProperty($guests->find())
            ->where(['Guests.created >=' => $startOfToday]);

        $total = $base()->count();
        $local = $base()->where(['Guests.guest_type' => 'local'])->count();
        $foreign = $base()->where(['Guests.guest_type' => 'foreign'])->count();

        // Guests currently staying = distinct guests with a checked-in reservation.
        $reservations = $this->fetchTable('Reservations');
        $inHouse = $this->countDistinct(
            $this->scopeToProperty(
                $reservations->find()->where([
                    'Reservations.status' => 'checked_in',
                    'Reservations.guest_id IS NOT' => null,
                ])
            ),
            'Reservations.guest_id'
        );

        $this->set('stats', compact('total', 'local', 'foreign', 'inHouse'));
        $this->viewBuilder()->setOption('serialize', ['stats']);
    }

    /**
     * GET /api/guests/match?full_name=&email=&contact_number=
     *
     * Returns existing guests that look like the same person (see
     * GuestsTable::findDuplicates). The Front Desk / Guests forms call this to
     * warn the receptionist before creating a possible duplicate.
     */
    public function match(): void
    {
        $this->authorize(Permissions::GUESTS_GUEST_VIEW);
        $propertyId = $this->effectivePropertyId();
        if ($propertyId === null) {
            throw new BadRequestException('property_id is required.');
        }

        $guests = $this->fetchTable('Guests');
        $duplicates = $guests->findDuplicates(
            $propertyId,
            (string)$this->request->getQuery('full_name'),
            $this->request->getQuery('email'),
            $this->request->getQuery('contact_number')
        );

        $this->set('duplicates', $duplicates);
        $this->viewBuilder()->setOption('serialize', ['duplicates']);
    }

    /**
     * GET /api/guests/{id} — guest with reservation history.
     */
    public function view(int $id): void
    {
        $this->authorize(Permissions::GUESTS_GUEST_VIEW);
        $guests = $this->fetchTable('Guests');
        $guest = $this->scopeToProperty($guests->find()->where(['Guests.id' => $id]))
            ->contain(['Reservations' => ['Rooms']])
            ->firstOrFail();

        $this->set('guest', $guest);
        $this->viewBuilder()->setOption('serialize', ['guest']);
    }

    /**
     * POST /api/guests
     */
    public function add(): void
    {
        $this->request->allowMethod('post');
        $this->authorize(Permissions::GUESTS_GUEST_MANAGE);

        $propertyId = $this->effectivePropertyId();
        if ($propertyId === null) {
            throw new BadRequestException('property_id is required.');
        }

        $guests = $this->fetchTable('Guests');

        // De-dup guard: surface look-alike guests so they can reuse one
        // instead. Creating anyway (`force`) records which ones it overrode,
        // and needs a reason (G3, GU3).
        $duplicates = $guests->findDuplicates(
            $propertyId,
            (string)$this->request->getData('full_name'),
            $this->request->getData('email'),
            $this->request->getData('contact_number'),
        );
        if (!$this->request->getData('force')) {
            if ($duplicates) {
                $this->response = $this->response->withStatus(409);
                $this->set('duplicates', $duplicates);
                $this->viewBuilder()->setOption('serialize', ['duplicates']);

                return;
            }
        }

        $guest = $guests->newEntity([
            'property_id' => $propertyId,
            'full_name' => $this->request->getData('full_name'),
            'nationality' => $this->request->getData('nationality'),
            'address' => $this->request->getData('address'),
            'contact_number' => $this->request->getData('contact_number'),
            'email' => $this->request->getData('email'),
            'guest_type' => $this->request->getData('guest_type') ?? 'local',
        ]);

        $saved = $guests->getConnection()->transactional(function () use ($guests, $guest, $duplicates): bool {
            if (!$guests->save($guest, ['atomic' => false])) {
                return false;
            }
            $this->recordGuestRegistration($this->eventContext(), $guest, GuestEventsTable::VIA_GUESTS, $duplicates);

            return true;
        });
        if (!$saved) {
            $this->validationFailed($guest->getErrors());

            return;
        }

        $this->response = $this->response->withStatus(201);
        $this->set('guest', $guest);
        $this->viewBuilder()->setOption('serialize', ['guest']);
    }

    /**
     * PATCH/PUT /api/guests/{id} — change a guest's details (final review G3).
     *
     * Lock, then check, then change, then record: each changed field is
     * recorded with before and after. A new name is `renamed` and needs a
     * reason (GU2: past invoices show the guest's current name); the other
     * fields are `details_updated`. An edit that changes nothing is refused
     * (400) and records nothing.
     */
    public function edit(int $id): void
    {
        $this->request->allowMethod(['patch', 'put', 'post']);
        $this->authorize(Permissions::GUESTS_GUEST_MANAGE);
        $guests = $this->fetchTable('Guests');
        $this->scopeToProperty($guests->find()->where(['Guests.id' => $id]))->firstOrFail();

        $data = [];
        foreach (GuestEventsTable::FIELDS as $field) {
            if ($this->request->getData($field) !== null) {
                $data[$field] = $this->request->getData($field);
            }
        }

        $guest = $guests->getConnection()->transactional(function () use ($guests, $id, $data) {
            $guest = $guests->find()->where(['Guests.id' => $id])->epilog('FOR UPDATE')->firstOrFail();
            $before = GuestEventsTable::detailsOf($guest);
            $guests->patchEntity($guest, $data, ['accessibleFields' => ['property_id' => false]]);
            $changes = GuestEventsTable::diff($before, GuestEventsTable::detailsOf($guest));
            if ($changes === []) {
                throw new BadRequestException('Nothing to change: the guest already reads like this.');
            }
            if (!$guests->save($guest, ['atomic' => false])) {
                return $guest;
            }
            /** @var \App\Model\Table\GuestEventsTable $events */
            $events = $this->fetchTable('GuestEvents');
            if (isset($changes['full_name'])) {
                $events->record($this->eventContext(), GuestEventsTable::RENAMED, $guest, [
                    'changes' => ['full_name' => $changes['full_name']],
                ]);
                unset($changes['full_name']);
            }
            if ($changes !== []) {
                $events->record($this->eventContext(), GuestEventsTable::DETAILS_UPDATED, $guest, [
                    'changes' => $changes,
                ]);
            }

            return $guest;
        });
        if ($guest->getErrors()) {
            $this->validationFailed($guest->getErrors());

            return;
        }

        $this->set('guest', $guest);
        $this->viewBuilder()->setOption('serialize', ['guest']);
    }

    /**
     * GET /api/guests/{id}/history[?page=] → { events, page, has_more }
     *
     * A guest's history, newest first (final review G3, Managers only, GU1):
     * where they were registered from, each change with before and after,
     * who made it and why. Imported registrations have no actor (`recorded`
     * false). Front Desk Staff see current details only.
     */
    public function history(int $id): void
    {
        $this->request->allowMethod('get');
        $this->authorize(Permissions::GUESTS_GUEST_VIEW_HISTORY, 'Only Managers can see who changed a guest.');
        $guest = $this->scopeToProperty($this->fetchTable('Guests')->find()->where(['Guests.id' => $id]))
            ->firstOrFail();

        $perPage = 25;
        $page = min(200, max(1, (int)($this->request->getQuery('page') ?? 1)));
        $rows = $this->fetchTable('GuestEvents')->find()
            ->where(['guest_id' => $guest->id])
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
            'recorded' => $r->source !== 'import',
            'reason' => $r->reason,
            'changes' => $r->changes,
            'snapshot' => $r->snapshot,
        ], $rows);

        $this->set(['events' => $events, 'page' => $page, 'has_more' => $hasMore]);
        $this->viewBuilder()->setOption('serialize', ['events', 'page', 'has_more']);
    }

    private function validationFailed(array $errors): void
    {
        $this->response = $this->response->withStatus(422);
        $this->set('errors', $errors);
        $this->viewBuilder()->setOption('serialize', ['errors']);
    }
}
