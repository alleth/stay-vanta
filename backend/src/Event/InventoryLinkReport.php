<?php
declare(strict_types=1);

namespace App\Event;

use Cake\Database\Connection;
use Cake\Log\Log;

/**
 * Menu items, recipes and options that still point at a deleted inventory
 * item (inventory follow-up, approved 2026-10-06 as I3): links left behind
 * before deletion was refused while in use. A sale of such a menu item takes
 * stock from an item nobody can see, or is refused for its "short stock".
 *
 * Reported, never repaired: which link the Manager meant to keep, replace or
 * remove isn't known, so a fix would be a guess. Each line names the menu
 * item to fix in POS, where the fix is recorded like any menu change.
 */
final class InventoryLinkReport
{
    /**
     * @param \Cake\Database\Connection $connection The application database.
     */
    public function __construct(private Connection $connection)
    {
    }

    /**
     * Every broken link, per property, oldest menu item first.
     *
     * @param int|null $propertyId Limit to one property.
     * @return list<array{property_id: int, menu_item_id: int, menu_item: string, kind: string, inventory_item_id: int, inventory_item: string}>
     */
    public function broken(?int $propertyId = null): array
    {
        $tables = $this->connection->getSchemaCollection()->listTables();
        foreach (['food_menu_items', 'food_menu_item_ingredients', 'food_menu_item_options', 'inventory_items'] as $t) {
            if (!in_array($t, $tables, true)) {
                return [];
            }
        }
        $where = $propertyId !== null ? 'AND m.property_id = ' . (int)$propertyId : '';
        $sql = "
            SELECT m.property_id, m.id AS menu_item_id, m.name AS menu_item, 'stock link' AS kind,
                i.id AS inventory_item_id, i.name AS inventory_item
            FROM food_menu_items m JOIN inventory_items i ON i.id = m.inventory_item_id
            WHERE m.deleted_at IS NULL AND i.deleted_at IS NOT NULL $where
            UNION ALL
            SELECT m.property_id, m.id, m.name, 'recipe', i.id, i.name
            FROM food_menu_item_ingredients r
            JOIN food_menu_items m ON m.id = r.food_menu_item_id
            JOIN inventory_items i ON i.id = r.inventory_item_id
            WHERE m.deleted_at IS NULL AND i.deleted_at IS NOT NULL $where
            UNION ALL
            SELECT m.property_id, m.id, m.name, CONCAT('option ', o.label), i.id, i.name
            FROM food_menu_item_options o
            JOIN food_menu_item_option_groups g ON g.id = o.food_menu_item_option_group_id
            JOIN food_menu_items m ON m.id = g.food_menu_item_id
            JOIN inventory_items i ON i.id = o.inventory_item_id
            WHERE m.deleted_at IS NULL AND i.deleted_at IS NOT NULL $where
            ORDER BY property_id, menu_item_id, kind";

        return array_map(fn(array $row) => [
            'property_id' => (int)$row['property_id'],
            'menu_item_id' => (int)$row['menu_item_id'],
            'menu_item' => (string)$row['menu_item'],
            'kind' => (string)$row['kind'],
            'inventory_item_id' => (int)$row['inventory_item_id'],
            'inventory_item' => (string)$row['inventory_item'],
        ], $this->connection->execute($sql)->fetchAll('assoc'));
    }

    /**
     * Log each broken link as a warning (none: one info line).
     *
     * @param int|null $propertyId Limit to one property.
     * @return list<array<string, mixed>> What broken() found.
     */
    public function report(?int $propertyId = null): array
    {
        $broken = $this->broken($propertyId);
        foreach ($broken as $b) {
            Log::warning(sprintf(
                'inventory link report, property %d: menu item %d "%s" (%s) uses deleted inventory item %d "%s"; '
                . 'fix it in POS',
                $b['property_id'],
                $b['menu_item_id'],
                $b['menu_item'],
                $b['kind'],
                $b['inventory_item_id'],
                $b['inventory_item'],
            ));
        }
        if ($broken === []) {
            Log::info('inventory link report: no menu item, recipe or option uses a deleted inventory item');
        }

        return $broken;
    }
}
