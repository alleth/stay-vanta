<?php
declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Puts the signed-in person's permissions version (Access::version(), Phase
 * 3c-2) on every response as X-Permissions-Version, refusals included (this
 * sits outside the error handler, like CorrelationIdMiddleware). The API
 * controller sets it once it knows who is asking; a response without a
 * signed-in person carries none. Screens compare it with the version they
 * hold and refresh when it differs.
 */
class PermissionsVersionMiddleware implements MiddlewareInterface
{
    /** Response header carrying the version. */
    public const HEADER = 'X-Permissions-Version';

    /**
     * The version of the request in progress (one request per PHP process).
     */
    private static ?string $current = null;

    /**
     * @param \Psr\Http\Message\ServerRequestInterface $request The request.
     * @param \Psr\Http\Server\RequestHandlerInterface $handler The next handler.
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        self::$current = null;
        try {
            $response = $handler->handle($request);
            $version = self::$current;
        } finally {
            self::$current = null;
        }

        return $version !== null ? $response->withHeader(self::HEADER, $version) : $response;
    }

    /**
     * Record the version for the request being handled.
     */
    public static function set(string $version): void
    {
        self::$current = $version;
    }
}
