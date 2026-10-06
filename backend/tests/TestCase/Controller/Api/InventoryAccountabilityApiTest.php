<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Api;

use App\Event\ConfigBaseline;
use App\Event\InventoryLinkReport;
use Cake\Database\Connection;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Inventory follow-up (approved 2026-10-06 as G1/G2, I1–I3): what an
 * inventory item is (name, unit, category, low-stock threshold, stock type,
 * parent) is recorded in config_changes with before and after; how much there
 * is stays the stock ledger's alone. Deleting needs a reason, is refused while
 * a menu item, recipe or option still uses the item (nothing is unlinked
 * automatically), and moves sub-items to the top level, each move recorded
 * under the deletion's reason. History is imported once, inventing nothing;
 * broken links are reported, never repaired.
 */
class InventoryAccountabilityApiTest extends TestCase
{
    use ApiScenarioTrait;
    use IntegrationTestTrait;
    use LocatorAwareTrait;

    private int $propertyId;
    private int $categoryId;
    private string $adminToken;
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $tag = uniqid();
        $this->propertyId = $this->createProperty('Inventory Inn');
        $this->adminToken = $this->makeUser($this->propertyId, 'admin', "inv-admin-$tag@example.test");
        $this->categoryId = $this->insertRow('InventoryCategories', [
            'property_id' => $this->propertyId, 'name' => 'Pantry', 'kind' => 'food_stock',
        ]);
        /** @var \Cake\Database\Connection $connection */
        $connection = $this->getTableLocator()->get('ConfigChanges')->getConnection();
        $this->connection = $connection;
    }

    protected function tearDown(): void
    {
        $this->cleanupScenario();
        parent::tearDown();
    }

    private function item(string $name, array $extra = []): int
    {
        return $this->insertRow('InventoryItems', [
            'property_id' => $this->propertyId, 'inventory_category_id' => $this->categoryId,
            'name' => $name, 'unit' => 'pc', 'quantity' => 10, 'tracking_type' => 'consumable',
        ] + $extra);
    }

    /**
     * @return list<\Cake\Datasource\EntityInterface>
     */
    private function changesOf(int $itemId, ?string $type = null): array
    {
        $query = $this->getTableLocator()->get('ConfigChanges')->find()
            ->where(['entity_type' => 'inventory_item', 'entity_id' => $itemId])
            ->orderBy(['id' => 'ASC']);
        if ($type !== null) {
            $query->where(['event_type' => $type]);
        }

        return $query->all()->toList();
    }

    private function deleteItem(int $itemId, ?string $reason): void
    {
        $this->callAs($this->adminToken, 'DELETE', "/api/inventory-items/$itemId", $reason ? ['reason' => $reason] : []);
    }

    public function testCreatingRecordsWhatTheItemIsWhileItsOpeningStockStaysInTheLedger(): void
    {
        $this->callAs($this->adminToken, 'POST', '/api/inventory-items', [
            'name' => 'Rice sack', 'unit' => 'kg', 'reorder_level' => 5,
            'inventory_category_id' => $this->categoryId, 'quantity' => 12,
        ]);
        $this->assertResponseOk();
        $itemId = (int)$this->responseJson()['item']['id'];

        [$created] = $this->changesOf($itemId);
        $this->assertSame('created', $created->event_type);
        $this->assertSame($this->userIdFor($this->adminToken), (int)$created->actor_id);
        $after = $created->changes['after'];
        $this->assertSame(['Rice sack', 'kg', 5], [$after['name'], $after['unit'], $after['reorder_level']]);
        $this->assertArrayNotHasKey('quantity', $after, 'stock is the stock ledger\'s');
        $this->assertArrayNotHasKey('total_quantity', $after);

        $movement = $this->getTableLocator()->get('StockMovements')->find()
            ->where(['inventory_item_id' => $itemId])->firstOrFail();
        $this->assertSame(12.0, (float)$movement->quantity);
        $this->assertSame($created->correlation_id, $movement->correlation_id, 'one request, one correlation');
    }

    public function testEditingRecordsBeforeAndAfterAndARepeatRecordsNothing(): void
    {
        $itemId = $this->item('Eggs');
        $body = ['name' => 'Eggs (tray)', 'unit' => 'tray', 'reorder_level' => 3, 'inventory_category_id' => $this->categoryId];

        $this->callAs($this->adminToken, 'PATCH', "/api/inventory-items/$itemId", $body);
        $this->assertResponseOk();
        [$updated] = $this->changesOf($itemId, 'updated');
        $this->assertEquals([
            'name' => ['before' => 'Eggs', 'after' => 'Eggs (tray)'],
            'unit' => ['before' => 'pc', 'after' => 'tray'],
            'reorder_level' => ['before' => 0, 'after' => 3],
        ], $updated->changes);

        $this->callAs($this->adminToken, 'PATCH', "/api/inventory-items/$itemId", $body);
        $this->assertResponseCode(400, 'nothing to change');
        $this->assertCount(1, $this->changesOf($itemId, 'updated'), 'no duplicated audit entry');
    }

    public function testQuantityChangesStayInTheStockLedgerOnly(): void
    {
        $itemId = $this->item('Towels');
        $before = count($this->changesOf($itemId));

        $this->callAs($this->adminToken, 'POST', '/api/stock-movements', [
            'inventory_item_id' => $itemId, 'direction' => 'in', 'quantity' => 5, 'reason' => 'restock',
        ]);
        $this->assertResponseCode(201);

        $this->assertSame(15.0, (float)$this->getTableLocator()->get('InventoryItems')->get($itemId)->quantity);
        $this->assertCount($before, $this->changesOf($itemId), 'a stock movement adds no configuration change');
    }

    public function testDeletingNeedsAReasonAndWithoutOneNothingChanges(): void
    {
        $itemId = $this->item('Soap');
        $this->deleteItem($itemId, null);
        $this->assertResponseCode(400);

        $this->assertNull($this->getTableLocator()->get('InventoryItems')->get($itemId)->deleted_at);
        $this->assertSame([], $this->changesOf($itemId, 'deleted'));
    }

    public function testDeletingIsRefusedWhileMenuItemsRecipesOrOptionsStillUseIt(): void
    {
        $itemId = $this->item('Eggs');
        $linked = $this->insertRow('FoodMenuItems', [
            'property_id' => $this->propertyId, 'name' => 'Boiled egg', 'price' => 30, 'inventory_item_id' => $itemId,
        ]);
        $withRecipe = $this->insertRow('FoodMenuItems', ['property_id' => $this->propertyId, 'name' => 'Silog', 'price' => 120]);
        $this->insertRow('FoodMenuItemIngredients', [
            'food_menu_item_id' => $withRecipe, 'inventory_item_id' => $itemId, 'quantity' => 1,
        ]);
        $withOption = $this->insertRow('FoodMenuItems', ['property_id' => $this->propertyId, 'name' => 'Fried rice', 'price' => 90]);
        $group = $this->insertRow('FoodMenuItemOptionGroups', [
            'food_menu_item_id' => $withOption, 'name' => 'Add-ons', 'kind' => 'addon',
        ]);
        $this->insertRow('FoodMenuItemOptions', [
            'food_menu_item_option_group_id' => $group, 'label' => 'Extra egg', 'price_delta' => 15,
            'inventory_item_id' => $itemId,
        ]);

        $this->deleteItem($itemId, 'Supplier stopped');
        $this->assertResponseCode(409);
        $body = $this->responseJson();
        $this->assertSame(['menu_item', 'recipe', 'option'], array_column($body['in_use'], 'kind'));
        $this->assertSame([$linked, $withRecipe, $withOption], array_column($body['in_use'], 'menu_item_id'));
        $this->assertStringContainsString('Remove these links in POS first', $body['message']);

        $this->assertNull($this->getTableLocator()->get('InventoryItems')->get($itemId)->deleted_at);
        $this->assertSame([], $this->changesOf($itemId, 'deleted'), 'a refusal records nothing');
        $this->assertSame($itemId, (int)$this->getTableLocator()->get('FoodMenuItems')->get($linked)->inventory_item_id, 'nothing is unlinked automatically');

        // Once the Manager has removed every link (in POS, recorded there), it goes.
        $this->getTableLocator()->get('FoodMenuItems')->updateAll(['inventory_item_id' => null], ['id' => $linked]);
        $this->getTableLocator()->get('FoodMenuItemIngredients')->deleteAll(['inventory_item_id' => $itemId]);
        $this->getTableLocator()->get('FoodMenuItemOptions')->updateAll(['inventory_item_id' => null], ['inventory_item_id' => $itemId]);
        $this->deleteItem($itemId, 'Supplier stopped');
        $this->assertResponseOk();
    }

    public function testDeletingMovesSubItemsToTheTopUnderTheDeletionsReason(): void
    {
        $parent = $this->item('Shampoo');
        $small = $this->item('Shampoo sachet', ['parent_id' => $parent]);
        $large = $this->item('Shampoo bottle', ['parent_id' => $parent]);
        $gone = $this->item('Shampoo tube', ['parent_id' => $parent, 'deleted_at' => new DateTime('-1 day')]);

        $this->deleteItem($parent, 'Brand discontinued');
        $this->assertResponseOk();

        [$deleted] = $this->changesOf($parent, 'deleted');
        $this->assertSame('Brand discontinued', $deleted->reason);
        $this->assertSame('Shampoo', $deleted->changes['before']['name']);
        foreach ([$small, $large] as $child) {
            $this->assertNull($this->getTableLocator()->get('InventoryItems')->get($child)->parent_id);
            [$moved] = $this->changesOf($child, 'updated');
            $this->assertEquals(['parent_id' => ['before' => $parent, 'after' => null]], $moved->changes);
            $this->assertSame('Brand discontinued', $moved->reason, 'the move inherits the deletion\'s reason');
            $this->assertSame($deleted->correlation_id, $moved->correlation_id);
            $this->assertSame((int)$deleted->actor_id, (int)$moved->actor_id);
        }
        $this->assertSame($parent, (int)$this->getTableLocator()->get('InventoryItems')->get($gone)->parent_id, 'a deleted sub-item is left alone');
        $this->assertSame([], $this->changesOf($gone, 'updated'));

        $this->deleteItem($parent, 'Again');
        $this->assertResponseCode(404, 'already deleted');
        $this->assertCount(1, $this->changesOf($parent, 'deleted'));
    }

    public function testTheBaselineRecordsEachItemOnceAndInventsNoDeletion(): void
    {
        $insert = function (string $name, ?string $deletedAt) {
            $this->connection->insert('inventory_items', [
                'property_id' => $this->propertyId, 'inventory_category_id' => $this->categoryId, 'name' => $name,
                'unit' => 'pc', 'quantity' => 4, 'reorder_level' => 1, 'tracking_type' => 'consumable',
                'deleted_at' => $deletedAt, 'created' => '2026-05-01 00:00:00', 'modified' => '2026-05-01 00:00:00',
            ]);

            return (int)$this->connection->execute('SELECT MAX(id) AS id FROM inventory_items')->fetch('assoc')['id'];
        };
        $active = $insert('Old candles', null);
        $deleted = $insert('Old matches', '2026-06-02 03:04:05');

        $baseline = new ConfigBaseline($this->connection);
        $baseline->run($this->propertyId);
        foreach ([$active, $deleted] as $itemId) {
            [$row] = $this->changesOf($itemId);
            $this->assertSame('baseline_recorded', $row->event_type);
            $this->assertSame('import', $row->source);
            $this->assertNull($row->actor_id, 'no actor was ever recorded');
            $this->assertNull($row->reason);
            $this->assertArrayNotHasKey('quantity', $row->changes['after']);
        }
        $this->assertArrayNotHasKey('deleted_at', $this->changesOf($active)[0]->changes['after']);
        $this->assertSame('2026-06-02 03:04:05', $this->changesOf($deleted)[0]->changes['after']['deleted_at']);
        $this->assertSame([], $this->changesOf($deleted, 'deleted'), 'who deleted it, and why, were never recorded');

        $baseline->run($this->propertyId);
        $this->assertCount(1, $this->changesOf($active), 'a second run adds nothing');
        $this->assertCount(1, $this->changesOf($deleted));
    }

    public function testBrokenLinksAreReportedButNeverRepaired(): void
    {
        $itemId = $this->item('Gone stock', ['deleted_at' => new DateTime('-2 days')]);
        $menuId = $this->insertRow('FoodMenuItems', [
            'property_id' => $this->propertyId, 'name' => 'Old special', 'price' => 50, 'inventory_item_id' => $itemId,
        ]);

        $broken = (new InventoryLinkReport($this->connection))->report($this->propertyId);

        $this->assertSame([[
            'property_id' => $this->propertyId, 'menu_item_id' => $menuId, 'menu_item' => 'Old special',
            'kind' => 'stock link', 'inventory_item_id' => $itemId, 'inventory_item' => 'Gone stock',
        ]], $broken);
        $this->assertSame($itemId, (int)$this->getTableLocator()->get('FoodMenuItems')->get($menuId)->inventory_item_id);
    }
}
