<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\Table;

/**
 * Who holds which role at which property (Permissions Phase 2, build step
 * 10b). A membership is never deleted: leaving a property sets `ended_at`,
 * and every grant, role change and end is an access event.
 */
class PropertyMembershipsTable extends Table
{
    /**
     * @param array<string, mixed> $config Table config.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('property_memberships');
        $this->addBehavior('Timestamp');
        $this->belongsTo('Users');
        $this->belongsTo('Properties');
        $this->belongsTo('Roles');
    }

    /**
     * Memberships not ended.
     *
     * @param \Cake\ORM\Query\SelectQuery $query The query.
     * @return \Cake\ORM\Query\SelectQuery
     */
    public function findActive(SelectQuery $query): SelectQuery
    {
        return $query->where([$this->aliasField('ended_at') . ' IS' => null]);
    }
}
