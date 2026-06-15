<?php
declare(strict_types=1);

namespace App\Middleware;

use Cake\Core\Configure;
use Cake\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Adds CORS headers so the React SPA (a different origin in production) can
 * call the API. Allowed origins come from the App.corsOrigins config; in
 * debug mode all origins are reflected for convenience.
 */
class CorsMiddleware implements MiddlewareInterface
{
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        $origin = $request->getHeaderLine('Origin');
        $allowed = (array)Configure::read('App.corsOrigins', []);

        $allowOrigin = null;
        if ($origin !== '' && (Configure::read('debug') || in_array($origin, $allowed, true))) {
            $allowOrigin = $origin;
        }

        // Answer CORS preflight directly with 204 — do NOT route it to a
        // controller (which would reject the OPTIONS method with a 405).
        if (strtoupper($request->getMethod()) === 'OPTIONS') {
            $response = (new Response())->withStatus(204);
        } else {
            $response = $handler->handle($request);
        }

        if ($allowOrigin !== null) {
            $response = $response
                ->withHeader('Access-Control-Allow-Origin', $allowOrigin)
                ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
                ->withHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, Accept')
                ->withHeader('Access-Control-Allow-Credentials', 'true')
                ->withHeader('Vary', 'Origin');
        }

        return $response;
    }
}
