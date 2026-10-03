<?php
declare(strict_types=1);

namespace App\Middleware;

use Cake\Utility\Text;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Gives every request one correlation id: the thread that ties together every
 * event a single business action writes, across all ledgers (a checkout's
 * room charge and its check-out, a sale and its stock movements). Recorded on
 * each event and on activity_index; ActivityIndexTable::forCorrelation()
 * reconstructs the whole action from it.
 *
 * Always generated here, never taken from the client: an id a caller could
 * choose could be made to stitch unrelated actions together. It's returned as
 * X-Request-Id on every response, errors included (this sits outside the
 * error handler), so a reported problem can be traced to its events.
 */
class CorrelationIdMiddleware implements MiddlewareInterface
{
    /** Request attribute holding the id. */
    public const ATTRIBUTE = 'correlationId';

    /** Response header carrying it back. */
    public const HEADER = 'X-Request-Id';

    /**
     * Assign the id, run the request, and put the id on the response.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request The request.
     * @param \Psr\Http\Server\RequestHandlerInterface $handler The next handler.
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $id = Text::uuid();
        $response = $handler->handle($request->withAttribute(self::ATTRIBUTE, $id));

        return $response->withHeader(self::HEADER, $id);
    }
}
