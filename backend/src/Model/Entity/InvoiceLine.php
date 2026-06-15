<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * InvoiceLine entity.
 *
 * @property int $id
 * @property int $invoice_id
 * @property string $description
 * @property string $amount
 * @property string|null $source_type   food_order | reservation
 * @property int|null $source_id
 */
class InvoiceLine extends Entity
{
    protected array $_accessible = [
        'invoice_id' => true,
        'description' => true,
        'amount' => true,
        'source_type' => true,
        'source_id' => true,
    ];
}
