<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Double clicks and retries on a slow connection: the same POST request
 * (same person, path and content) is processed once. A copy that arrives
 * while the first one runs waits for it, then gets the same answer.
 * Toggles (PATCH) are never merged: open then close must both happen.
 *
 * A form can send an Idempotency-Key header (one key per submission): then
 * the key, not the content, identifies the request, for 10 minutes.
 */
class PreventDuplicateRequests
{
    private const KEY_TTL = 600;

    public function handle(Request $request, Closure $next): Response
    {
        $window = (int) config('app.duplicate_request_window', 10);
        $idempotencyKey = substr((string) $request->header('Idempotency-Key'), 0, 100);

        // By content only POST (creations, queue actions): a PATCH toggle sent
        // twice is open-then-close, and PUT/DELETE give the same result anyway
        if ($window <= 0 || $request->isMethod('GET') || $request->isMethod('HEAD')
            || ($idempotencyKey === '' && !$request->isMethod('POST'))) {
            return $next($request);
        }

        $key = 'dup:' . sha1(implode('|', [
            $this->actor($request),
            $request->method(),
            $request->path(),
            $idempotencyKey !== '' ? 'key:' . $idempotencyKey : $this->payloadHash($request),
        ]));
        $ttl = $idempotencyKey !== '' ? self::KEY_TTL : $window;

        try {
            return Cache::lock($key . ':lock', 60)->block(20, function () use ($key, $ttl, $request, $next) {
                if ($cached = Cache::get($key)) {
                    return response($cached['content'], $cached['status'])
                        ->header('Content-Type', 'application/json')
                        ->header('X-Duplicate-Request', 'replayed');
                }

                $response = $next($request);

                // Only JSON answers are replayed, and never a server error (the retry may succeed)
                if ($response instanceof JsonResponse && $response->getStatusCode() < 500) {
                    Cache::put($key, ['status' => $response->getStatusCode(), 'content' => $response->getContent()], $ttl);
                }

                return $response;
            });
        } catch (LockTimeoutException) {
            return response()->json(['message' => 'الطلب نفسه قيد المعالجة، انتظر قليلاً ثم تحقق من النتيجة'], 429);
        }
    }

    /** The logged-in user, or the client address for public forms. */
    private function actor(Request $request): string
    {
        $user = $request->user('sanctum');

        return $user ? 'user:' . $user->getAuthIdentifier() : 'ip:' . $request->ip();
    }

    private function payloadHash(Request $request): string
    {
        $files = collect(Arr::flatten($request->allFiles()))
            ->map(fn ($file) => $file->getClientOriginalName() . ':' . $file->getSize())
            ->all();

        return sha1(json_encode([$request->except(array_keys($request->allFiles())), $files]));
    }
}
