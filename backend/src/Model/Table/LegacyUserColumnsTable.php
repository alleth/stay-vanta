<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;

/**
 * The archive of users.role and users.property_id (Permissions Phase 3d): one
 * row per account, copied once by 3d-3 before the columns are dropped, with a
 * per-row checksum (App\Auth\LegacyUserColumns). Append-only: never updated or
 * deleted. Nothing reads it at request time; it exists for audits and to undo
 * the drop.
 */
class LegacyUserColumnsTable extends Table
{
    use AppendOnlyTableTrait;

    /**
     * @param array<string, mixed> $config Table config.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('legacy_user_columns');
        $this->setPrimaryKey('id');
        $this->addBehavior('AppendOnly');
    }
}
