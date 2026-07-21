<?php
declare(strict_types=1);

namespace App\Controller\Api;

use Cake\Http\Exception\BadRequestException;

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
     * GET /api/guests/stats — counts for the guests dashboard.
     * { total, local, foreign, in_house }
     *
     * total/local/foreign are *today's* registrations (they reset to 0 each
     * day for a fresh-start view); in_house is whoever is checked in right now.
     */
    public function stats(): void
    {
        $guests = $this->fetchTable('Guests');
        $startOfToday = date('Y-m-d 00:00:00');
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

        $propertyId = $this->effectivePropertyId();
        if ($propertyId === null) {
            throw new BadRequestException('property_id is required.');
        }

        $guests = $this->fetchTable('Guests');

        // De-dup guard: unless the user explicitly chose "create anyway" (force),
        // surface look-alike guests so they can reuse one instead.
        if (!$this->request->getData('force')) {
            $duplicates = $guests->findDuplicates(
                $propertyId,
                (string)$this->request->getData('full_name'),
                $this->request->getData('email'),
                $this->request->getData('contact_number')
            );
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

        if (!$guests->save($guest)) {
            $this->validationFailed($guest->getErrors());

            return;
        }

        $this->response = $this->response->withStatus(201);
        $this->set('guest', $guest);
        $this->viewBuilder()->setOption('serialize', ['guest']);
    }

    /**
     * PATCH /api/guests/{id}
     */
    public function edit(int $id): void
    {
        $this->request->allowMethod(['patch', 'put', 'post']);
        $guests = $this->fetchTable('Guests');
        $guest = $this->scopeToProperty($guests->find()->where(['Guests.id' => $id]))->firstOrFail();

        $guests->patchEntity($guest, [
            'full_name' => $this->request->getData('full_name'),
            'nationality' => $this->request->getData('nationality'),
            'address' => $this->request->getData('address'),
            'contact_number' => $this->request->getData('contact_number'),
            'email' => $this->request->getData('email'),
            'guest_type' => $this->request->getData('guest_type'),
        ], ['accessibleFields' => ['property_id' => false]]);

        if (!$guests->save($guest)) {
            $this->validationFailed($guest->getErrors());

            return;
        }

        $this->set('guest', $guest);
        $this->viewBuilder()->setOption('serialize', ['guest']);
    }

    private function validationFailed(array $errors): void
    {
        $this->response = $this->response->withStatus(422);
        $this->set('errors', $errors);
        $this->viewBuilder()->setOption('serialize', ['errors']);
    }
}
