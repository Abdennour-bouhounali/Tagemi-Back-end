<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Large JSON answers (the event's appointment list for the presence desk:
 * ~2 MB for 1,400 appointments) are sent gzip-compressed, about 10x smaller.
 * The web server's default gzip doesn't cover application/json; when it does,
 * it leaves an answer that is already compressed alone.
 */
class CompressJsonResponse
{
    private const MIN_BYTES = 8192;

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (!str_contains(strtolower((string) $request->header('Accept-Encoding')), 'gzip')
            || $response->headers->has('Content-Encoding')
            || !str_contains((string) $response->headers->get('Content-Type'), 'json')
            || !function_exists('gzencode')) {
            return $response;
        }

        $content = $response->getContent();
        if ($content === false || strlen($content) < self::MIN_BYTES) {
            return $response;
        }

        $compressed = gzencode($content, 5);
        if ($compressed === false) {
            return $response;
        }

        $response->setContent($compressed);
        $response->headers->set('Content-Encoding', 'gzip');
        $response->headers->set('Content-Length', (string) strlen($compressed));
        $response->setVary('Accept-Encoding', false);

        return $response;
    }
}
