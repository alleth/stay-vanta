<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Api;

use App\Event\EventContext;
use App\Model\Table\UsersTable;
use Cake\I18n\DateTime;

/**
 * Shared scaffolding for HTTP-level API tests.
 *
 * No fixtures, as in the existing API suites: each test builds the
 * properties, users and rows it needs, and tearDown() removes everything
 * created under those properties (plus any platform owner). Use it from a
 * TestCase that also uses IntegrationTestTrait and LocatorAwareTrait, and
 * call cleanupScenario() from tearDown().
 */
trait ApiScenarioTrait
{
    /**
     * @var list<int> Properties created by this test, removed in cleanupScenario().
     */
    private array $scenarioPropertyIds = [];

    /**
     * @var list<int> Users without a property (platform owners), removed in cleanupScenario().
     */
    private array $scenarioOwnerIds = [];

    /**
     * Every table holding rows for one property, children before parents
     * (there are no foreign keys, so order is for readability only).
     */
    private const PROPERTY_TABLES = [
        'ReservationDiscounts' => 'reservation_id',
        'ReservationExtraCharges' => 'reservation_id',
        'FoodOrderDiscounts' => 'food_order_id',
        'FoodOrderItems' => 'food_order_id',
        'InvoiceLines' => 'invoice_id',
    ];

    private const PROPERTY_SCOPED_TABLES = [
        'FoodOrders', 'Invoices', 'Reservations', 'Guests', 'FoodMenuItems',
        'InventoryItems', 'InventoryCategories', 'ReceiptSeries', 'ExtraCharges', 'PromoRates',
        'BookingSources', 'RoomRates', 'Rooms', 'PropertyMemberships', 'Users',
    ];

    /**
     * Append-only ledgers: their tables refuse deletes, so cleanup removes
     * test rows through the connection, the one place that may.
     */
    private const LEDGER_TABLES = [
        'activity_index', 'food_order_events', 'stock_movements', 'invoice_events', 'reservation_events', 'config_changes',
        'access_events',
    ];

    protected function createProperty(string $name): int
    {
        $properties = $this->getTableLocator()->get('Properties');
        $property = $properties->saveOrFail($properties->newEntity([
            'name' => $name,
            'subscription_status' => 'active',
        ]), ['eventContext' => EventContext::system(null, 'test-fixture')]);
        $this->scenarioPropertyIds[] = (int)$property->id;

        return (int)$property->id;
    }

    /**
     * Create an active user and return their plaintext bearer token.
     * A `null` property makes a platform owner.
     */
    protected function makeUser(?int $propertyId, string $role, string $email, bool $active = true): string
    {
        $users = $this->getTableLocator()->get('Users');
        [$token, $digest] = UsersTable::issueToken();
        $user = $users->saveOrFail($users->newEntity([
            'property_id' => $propertyId,
            'name' => ucfirst($role) . ' ' . $email,
            'email' => $email,
            'password' => 'secret123',
            'role' => $role,
            'is_active' => true,
        ]));
        // Set after creation: these fields aren't mass-assignable.
        $user->set('api_token', $digest);
        $user->set('token_expires', new DateTime('+30 days'));
        $user->set('is_active', $active);
        $users->saveOrFail($user);
        if ($propertyId === null) {
            $this->scenarioOwnerIds[] = (int)$user->id;
        }
        // Where their access comes from (step 10b): the platform flag, or a
        // membership with their role at the property.
        if ($propertyId === null && $role === 'owner') {
            $users->updateAll(['is_platform' => true], ['id' => $user->id]);
        } elseif ($propertyId !== null) {
            $roleId = $this->getTableLocator()->get('Roles')->idFor($role);
            if ($roleId !== null) {
                $this->insertRow('PropertyMemberships', [
                    'user_id' => (int)$user->id,
                    'property_id' => $propertyId,
                    'role_id' => $roleId,
                    'started_at' => new DateTime(),
                ]);
            }
        }

        return $token;
    }

    /**
     * The id of the user a token belongs to.
     */
    protected function userIdFor(string $token): int
    {
        return (int)$this->getTableLocator()->get('Users')->find()
            ->where(['api_token' => UsersTable::hashToken($token)])
            ->firstOrFail()->id;
    }

    /**
     * Sign the next request. Needed before every request: request config
     * doesn't survive one, so a second call would otherwise come back 401.
     */
    protected function authedAs(?string $token): void
    {
        $headers = ['Accept' => 'application/json', 'Content-Type' => 'application/json'];
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        $this->configRequest(['headers' => $headers]);
    }

    /**
     * Send a signed request. `$body` is JSON-encoded for writes.
     */
    protected function callAs(?string $token, string $method, string $url, array $body = []): void
    {
        $this->authedAs($token);
        $method = strtolower($method);
        if ($method === 'get') {
            $this->get($url);
        } elseif ($method === 'delete') {
            if ($body === []) {
                $this->delete($url);
            } else {
                // Elevated deletes carry their reason in the body (step 8).
                $this->_sendRequest($url, 'DELETE', json_encode($body));
            }
        } else {
            $this->{$method}($url, json_encode($body));
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function responseJson(): array
    {
        return (array)json_decode((string)$this->_response->getBody(), true);
    }

    /**
     * Insert a row directly (no API, no validation) for arranging a scenario.
     *
     * @param array<string, mixed> $data
     */
    protected function insertRow(string $table, array $data): int
    {
        $repo = $this->getTableLocator()->get($table);
        $entity = $repo->newEntity($data, ['validate' => false, 'accessibleFields' => ['*' => true]]);
        // Configuration rows are audited (step 9): fixtures are made by the system.
        $repo->saveOrFail($entity, [
            'checkRules' => false,
            'eventContext' => EventContext::system($data['property_id'] ?? null, 'test-fixture'),
        ]);

        return (int)$entity->id;
    }

    protected function cleanupScenario(): void
    {
        $locator = $this->getTableLocator();
        foreach ($this->scenarioPropertyIds as $propertyId) {
            // Soft-deleted ones too (step 8): their discounts and extras still exist.
            $reservationIds = $locator->get('Reservations')->find('all', withDeleted: true)
                ->where(['property_id' => $propertyId])->all()->extract('id')->toList();
            $orderIds = $locator->get('FoodOrders')->find()
                ->where(['property_id' => $propertyId])->all()->extract('id')->toList();
            $invoiceIds = $locator->get('Invoices')->find()
                ->where(['property_id' => $propertyId])->all()->extract('id')->toList();
            $parents = ['reservation_id' => $reservationIds, 'food_order_id' => $orderIds, 'invoice_id' => $invoiceIds];
            foreach (self::PROPERTY_TABLES as $table => $key) {
                if ($parents[$key]) {
                    $locator->get($table)->deleteAll([$key . ' IN' => $parents[$key]]);
                }
            }
            $connection = $locator->get('Properties')->getConnection();
            foreach (self::LEDGER_TABLES as $table) {
                $connection->delete($table, ['property_id' => $propertyId]);
            }
            foreach (self::PROPERTY_SCOPED_TABLES as $table) {
                $locator->get($table)->deleteAll(['property_id' => $propertyId]);
            }
            $locator->get('Properties')->deleteAll(['id' => $propertyId]);
        }
        if ($this->scenarioOwnerIds) {
            // Platform events (step 10) have no property: cleared by person.
            $locator->get('Users')->getConnection()
                ->delete('access_events', ['subject_user_id IN' => $this->scenarioOwnerIds]);
            $locator->get('PropertyMemberships')->deleteAll(['user_id IN' => $this->scenarioOwnerIds]);
            $locator->get('Users')->deleteAll(['id IN' => $this->scenarioOwnerIds]);
        }
        $this->scenarioPropertyIds = [];
        $this->scenarioOwnerIds = [];
    }
}
