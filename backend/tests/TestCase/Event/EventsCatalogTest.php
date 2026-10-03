<?php
declare(strict_types=1);

namespace App\Test\TestCase\Event;

use App\Event\EventLedgers;
use Cake\Core\App;
use Cake\TestSuite\TestCase;
use RuntimeException;

/**
 * docs/EVENTS.md and the ledger classes must say the same thing: the same
 * ledgers, event types, required reasons and grace entries.
 */
class EventsCatalogTest extends TestCase
{
    /**
     * @return array<string, array{table: string, types: list<string>, reason: list<string>, grace: list<string>}>
     */
    private function documented(): array
    {
        $text = (string)file_get_contents(dirname(ROOT) . '/docs/EVENTS.md');
        if (!preg_match('/^## Ledgers\R(.*?)(?=^## )/ms', $text, $section)) {
            throw new RuntimeException('docs/EVENTS.md has no "## Ledgers" section.');
        }
        $ledgers = [];
        $current = null;
        foreach (preg_split('/\R/', $section[1]) as $line) {
            if (preg_match('/^### ([a-z_]+) \(`([A-Za-z]+)`\)$/', $line, $m)) {
                $current = $m[2];
                $ledgers[$current] = ['table' => $m[1], 'types' => [], 'reason' => [], 'grace' => []];
            } elseif ($current && preg_match('/^\| `([a-z_]+)` \|([^|]*)\|([^|]*)\|/', $line, $m)) {
                $ledgers[$current]['types'][] = $m[1];
                if (trim($m[2]) === 'yes') {
                    $ledgers[$current]['reason'][] = $m[1];
                }
                if (trim($m[3]) === 'yes') {
                    $ledgers[$current]['grace'][] = $m[1];
                }
            }
        }

        return $ledgers;
    }

    public function testEveryLedgerIsDocumentedAndEveryDocumentedLedgerExists(): void
    {
        $this->assertEqualsCanonicalizing(array_keys(EventLedgers::TABLES), array_keys($this->documented()));
    }

    public function testTypesAndReasonRulesMatchTheCode(): void
    {
        foreach ($this->documented() as $alias => $doc) {
            $class = App::className($alias, 'Model/Table', 'Table');
            $this->assertNotNull($class, "$alias has no Table class");
            $this->assertSame($doc['types'], $class::TYPES, "$alias TYPES");
            $this->assertSame($doc['reason'], $class::REQUIRES_REASON, "$alias REQUIRES_REASON");
            $this->assertSame($doc['grace'], $class::REASON_GRACE, "$alias REASON_GRACE");
            $this->assertSame([], array_diff($class::REASON_GRACE, $class::REQUIRES_REASON), "$alias grace ⊆ required");
            foreach ($class::TYPES as $type) {
                $this->assertMatchesRegularExpression('/^[a-z]+(_[a-z]+)*$/', $type, 'past-tense snake_case');
            }
        }
    }
}
