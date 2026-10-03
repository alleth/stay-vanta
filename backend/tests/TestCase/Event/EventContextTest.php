<?php
declare(strict_types=1);

namespace App\Test\TestCase\Event;

use App\Event\EventContext;
use Cake\TestSuite\TestCase;
use InvalidArgumentException;

/**
 * The context every ledger write carries: who, where, which action, why.
 */
class EventContextTest extends TestCase
{
    public function testAWebEventNeedsAnActorAndACorrelationId(): void
    {
        $context = new EventContext(7, 'admin', 3, 'corr-1');
        $this->assertSame(7, $context->actorId);
        $this->assertSame('web', $context->source);

        foreach ([[null, 'corr-1', 'web'], [7, '', 'web'], [7, '   ', 'web'], [7, 'corr-1', 'email']] as [$actor, $id, $source]) {
            try {
                new EventContext($actor, null, 3, $id, $source);
                $this->fail("accepted actor=$actor id='$id' source=$source");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testABlankReasonIsNoReason(): void
    {
        $this->assertNull((new EventContext(7, 'admin', 3, 'c', reason: '   '))->reason);
        $this->assertSame('Guest complaint', (new EventContext(7, 'admin', 3, 'c', reason: ' Guest complaint '))->reason);
    }

    public function testWithReasonKeepsTheActionAndItsClock(): void
    {
        $context = new EventContext(7, 'admin', 3, 'corr-9');
        $withReason = $context->withReason('Wrong item');

        $this->assertSame('Wrong item', $withReason->reason);
        $this->assertSame('corr-9', $withReason->correlationId);
        $this->assertSame($context->now, $withReason->now);
        $this->assertNull($context->reason, 'the original is unchanged');
    }

    public function testSystemWorkHasNoActorAndItsOwnCorrelationId(): void
    {
        $a = EventContext::system(3, 'nightly-audit');
        $b = EventContext::system(3, 'nightly-audit');

        $this->assertNull($a->actorId);
        $this->assertSame('system', $a->source);
        $this->assertStringStartsWith('system-nightly-audit-', $a->correlationId);
        $this->assertNotSame($a->correlationId, $b->correlationId);
    }
}
