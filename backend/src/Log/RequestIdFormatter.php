<?php
declare(strict_types=1);

namespace App\Log;

use App\Middleware\CorrelationIdMiddleware;
use Cake\Log\Formatter\DefaultFormatter;

/**
 * Prefixes every log line written during a request with that request's
 * correlation id, `[req:<id>]` — the same id the client got as X-Request-Id
 * and every event of the request carries. One id then links a user's report,
 * the server log and the ledger rows.
 */
class RequestIdFormatter extends DefaultFormatter
{
    /**
     * @param mixed $level The log level.
     * @param string $message The message.
     * @param array<string, mixed> $context Log context.
     * @return string
     */
    public function format(mixed $level, string $message, array $context = []): string
    {
        $id = CorrelationIdMiddleware::current();

        return parent::format($level, $id !== null ? "[req:$id] $message" : $message, $context);
    }
}
