<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'cros' => \App\Http\Middleware\Cors::class,
            'role' => \App\Http\Middleware\EnsureUserHasRole::class,
            'event.access' => \App\Http\Middleware\EnsureEventAccess::class,
        ]);
        // API only: there is no login page to redirect guests to
        $middleware->redirectGuestsTo(fn () => null);
        // Double clicks / slow-network retries are processed once
        $middleware->api(append: [\App\Http\Middleware\PreventDuplicateRequests::class]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // An expired token gets a JSON 401, not an HTML error page
        $exceptions->shouldRenderJsonWhen(fn ($request) => $request->is('api/*') || $request->expectsJson());
    })->create();
