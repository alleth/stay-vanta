<?php
declare(strict_types=1);

namespace App\Test\TestCase\Error;

use App\Error\AppErrorLogger;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\ForbiddenException;
use Cake\Http\Exception\InternalErrorException;
use Cake\Http\Exception\UnauthorizedException;
use Cake\Http\ServerRequest;
use Cake\Log\Engine\ArrayLog;
use Cake\Log\Log;
use Cake\Routing\Exception\MissingRouteException;
use Cake\TestSuite\TestCase;
use RuntimeException;

/**
 * Expected refusals stay out of the error log but remain traceable; real
 * failures are still errors with their trace.
 */
class AppErrorLoggerTest extends TestCase
{
    private const ENGINE = 'app_error_logger_test';

    protected function setUp(): void
    {
        parent::setUp();
        Log::setConfig(self::ENGINE, ['className' => ArrayLog::class, 'levels' => []]);
    }

    protected function tearDown(): void
    {
        Log::drop(self::ENGINE);
        parent::tearDown();
    }

    /**
     * @return list<string>
     */
    private function lines(): array
    {
        /** @var \Cake\Log\Engine\ArrayLog $engine */
        $engine = Log::engine(self::ENGINE);

        return $engine->read();
    }

    public function testExpectedRefusalsAreOneInfoLine(): void
    {
        $request = new ServerRequest(['url' => '/api/guests/42', 'environment' => ['REQUEST_METHOD' => 'GET']]);
        $refusals = [
            new UnauthorizedException('Missing or invalid token.'),
            new ForbiddenException('Only a Manager can delete a reservation.'),
            new BadRequestException('A reason is required for this action.'),
            new RecordNotFoundException('Record not found in table `guests`.'),
            new MissingRouteException(['url' => '/api/nope', 'method' => 'GET']),
        ];
        foreach ($refusals as $exception) {
            (new AppErrorLogger())->logException($exception, $request, true);
        }

        $lines = $this->lines();
        $this->assertCount(count($refusals), $lines);
        foreach ($lines as $line) {
            $this->assertStringStartsWith('info: ', $line);
            $this->assertStringContainsString(' GET /api/guests/42: ', $line);
            $this->assertStringNotContainsString('Stack Trace', $line);
        }
        $this->assertStringContainsString('info: 401 GET /api/guests/42: Missing or invalid token.', $lines[0]);
    }

    public function testRealFailuresAreStillErrorsWithTheirTrace(): void
    {
        foreach ([new RuntimeException('Deadlock found'), new InternalErrorException('Boom')] as $exception) {
            (new AppErrorLogger())->logException($exception, null, true);
        }

        foreach ($this->lines() as $line) {
            $this->assertStringStartsWith('error: ', $line);
            $this->assertStringContainsString('Stack Trace', $line);
        }
        $this->assertCount(2, $this->lines());
    }
}
