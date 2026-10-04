<?php
declare(strict_types=1);

namespace App\Event;

/**
 * Every table that records events through EventLedgerBehavior, by table
 * alias, with the subject type it writes to activity_index. docs/EVENTS.md
 * documents each one; EventsCatalogTest keeps the two in step.
 */
final class EventLedgers
{
    public const TABLES = [
        'FoodOrderEvents' => 'food_order',
        'StockMovements' => 'inventory_item',
        'InvoiceEvents' => 'invoice',
        'ReservationEvents' => 'reservation',
        // One ledger for every configuration table; each index row names its entity type.
        'ConfigChanges' => 'configuration',
    ];
}
