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
        $middleware->api(append: [
            // Double clicks / slow-network retries are processed once
            \App\Http\Middleware\PreventDuplicateRequests::class,
            // Audit trail of staff changes and logins (super admin: «سجل العمليات»)
            \App\Http\Middleware\RecordAdminAction::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // An expired token gets a JSON 401, not an HTML error page
        $exceptions->shouldRenderJsonWhen(fn ($request) => $request->is('api/*') || $request->expectsJson());
        // Same person twice in one specialty (another path than the checked ones): a clear refusal, not a 500
        $exceptions->render(function (\Illuminate\Database\UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'appointments_unique_person_per_specialty')) {
                return response()->json(['message' => 'نفس الشخص مسجل في هذا التخصص مسبقاً (ربما برقم آخر)، ادمج التسجيلين أو احذف الزائد'], 409);
            }
        });
    })->create();
