<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\Datasource\EntityInterface;
use Cake\I18n\DateTime;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\Table;

/**
 * Support sessions (build step 10b, A6): the Platform Owner reading one
 * property's data, read-only, for SUPPORT_MINUTES, with a reason. The row is
 * the session's state; its history is in access_events. Never deleted.
 *
 * A session is open until it's ended (`ended_at`, by the Platform Owner or
 * the property's Manager) or its `expires_at` passes. Expiry writes nothing:
 * the end time was fixed when the session started, so the record already
 * says when it ended.
 */
class SupportSessionsTable extends Table
{
    /** How long a support session lasts (A6). */
    public const SUPPORT_MINUTES = 60;

    /**
     * @param array<string, mixed> $config Table config.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('support_sessions');
        $this->addBehavior('Timestamp');
        $this->belongsTo('Users');
        $this->belongsTo('Properties');
        $this->belongsTo('EndedBy', [
            'className' => 'Users',
            'foreignKey' => 'ended_by',
            'propertyName' => 'ended_by_user',
        ]);
    }

    /**
     * Sessions open now: not ended and not expired.
     *
     * @param \Cake\ORM\Query\SelectQuery $query The query.
     * @return \Cake\ORM\Query\SelectQuery
     */
    public function findOpen(SelectQuery $query): SelectQuery
    {
        return $query->where([
            $this->aliasField('ended_at') . ' IS' => null,
            $this->aliasField('expires_at') . ' >' => DateTime::now(),
        ]);
    }

    /**
     * A session's state as the screens name it: open, ended or expired.
     *
     * @param \Cake\Datasource\EntityInterface $session The session.
     */
    public static function stateOf(EntityInterface $session): string
    {
        if ($session->get('ended_at') !== null) {
            return 'ended';
        }

        return $session->get('expires_at')->isPast() ? 'expired' : 'open';
    }
}
