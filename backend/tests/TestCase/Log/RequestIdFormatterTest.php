<?php
declare(strict_types=1);

namespace App\Test\TestCase\Log;

use App\Log\RequestIdFormatter;
use App\Middleware\CorrelationIdMiddleware;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\TestSuite\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A log line written while a request runs carries that request's
 * X-Request-Id, so support can go from a user's report to the server log to
 * the events with one id.
 */
class RequestIdFormatterTest extends TestCase
{
    public function testLinesDuringARequestCarryItsId(): void
    {
        $formatter = new RequestIdFormatter(['includeDate' => false]);
        $handler = new class ($formatter) implements RequestHandlerInterface {
            public string $line = '';

            public function __construct(private RequestIdFormatter $formatter)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->line = $this->formatter->format('error', 'Settlement failed');

                return new Response();
            }
        };

        $response = (new CorrelationIdMiddleware())->process(new ServerRequest(), $handler);
        $id = $response->getHeaderLine('X-Request-Id');

        $this->assertSame("error: [req:$id] Settlement failed", $handler->line);
    }

    public function testLinesOutsideARequestAreUnchanged(): void
    {
        $this->assertNull(CorrelationIdMiddleware::current());
        $this->assertSame(
            'error: Nightly job failed',
            (new RequestIdFormatter(['includeDate' => false]))->format('error', 'Nightly job failed'),
        );
    }
}
