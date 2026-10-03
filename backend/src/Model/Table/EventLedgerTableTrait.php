<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Event\EventContext;
use Cake\Datasource\EntityInterface;

/**
 * Gives a ledger table its record() method, handing over to its
 * EventLedgerBehavior (CakePHP 5.3 deprecates calling behavior methods on the
 * table directly). Use it in every table that attaches the behavior.
 */
trait EventLedgerTableTrait
{
    /**
     * Record that `$type` happened to `$subject`; see EventLedgerBehavior::record().
     *
     * @param \App\Event\EventContext $context Who, for whom, why, which action.
     * @param string $type One of the table's TYPES.
     * @param \Cake\Datasource\EntityInterface $subject The record the event is about.
     * @param array<string, mixed> $options `changes`, `columns`, `correctsEventId`.
     * @return \Cake\Datasource\EntityInterface The stored event.
     */
    public function record(
        EventContext $context,
        string $type,
        EntityInterface $subject,
        array $options = [],
    ): EntityInterface {
        /** @var \App\Model\Behavior\EventLedgerBehavior $ledger */
        $ledger = $this->getBehavior('EventLedger');

        return $ledger->record($context, $type, $subject, $options);
    }
}
