<?php
declare(strict_types=1);

namespace App\Error;

use Cake\Core\Exception\HttpErrorCodeInterface;
use Cake\Error\ErrorLogger;
use Cake\Http\Exception\HttpException;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * Keeps the error log for genuine failures.
 *
 * A refusal the API gives on purpose — not signed in (401), not allowed (403),
 * a bad request or a missing reason (400), a record or route that doesn't
 * exist (404), a wrong method (405), a conflict (409) — is an expected
 * outcome, not a fault. Logging each with a stack trace at error level buried
 * real failures. Those now go out as one `info` line (status, method, path,
 * message; the [req:<id>] prefix comes from RequestIdFormatter), so a user's
 * report can still be traced. Anything else, every 5xx included, is logged
 * as before: an error with its trace.
 */
class AppErrorLogger extends ErrorLogger
{
    /**
     * @param \Throwable $exception The exception.
     * @param \Psr\Http\Message\ServerRequestInterface|null $request The request, if any.
     * @param bool $includeTrace Whether to include a stack trace.
     * @return void
     */
    public function logException(
        Throwable $exception,
        ?ServerRequestInterface $request = null,
        bool $includeTrace = false,
    ): void {
        if (!self::isClientError($exception)) {
            parent::logException($exception, $request, $includeTrace);

            return;
        }

        $line = sprintf('%d %s', $exception->getCode(), $exception->getMessage());
        if ($request !== null) {
            $line = sprintf(
                '%d %s %s: %s',
                $exception->getCode(),
                $request->getMethod(),
                $request->getUri()->getPath(),
                $exception->getMessage(),
            );
        }
        $this->log('info', $line);
    }

    /**
     * Whether an exception is an expected 4xx answer rather than a fault.
     */
    public static function isClientError(Throwable $exception): bool
    {
        $code = $exception->getCode();

        return ($exception instanceof HttpException || $exception instanceof HttpErrorCodeInterface)
            && is_int($code) && $code >= 400 && $code < 500;
    }
}
