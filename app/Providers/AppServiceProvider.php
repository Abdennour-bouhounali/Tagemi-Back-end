<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Brute-force protection: keyed by email + IP so that many staff
        // behind one venue/mobile-carrier IP don't block each other.
        RateLimiter::for('login', function (Request $request) {
            return [
                Limit::perMinute(5)->by(strtolower((string) $request->input('email')) . '|' . $request->ip()),
                Limit::perMinute(30)->by($request->ip()),
            ];
        });

        RateLimiter::for('register', function (Request $request) {
            return Limit::perHour(10)->by($request->ip());
        });

        // Public forms (booking, evaluation, contact, volunteer). Generous on
        // purpose: in Algeria many users share a carrier-grade NAT IP.
        RateLimiter::for('public-forms', function (Request $request) {
            return Limit::perMinute(20)->by($request->ip());
        });

        // Health data behind three answers: keep guessing slow
        RateLimiter::for('my-data', function (Request $request) {
            return [Limit::perMinute(5)->by($request->ip()), Limit::perDay(30)->by($request->ip())];
        });
    }
}
