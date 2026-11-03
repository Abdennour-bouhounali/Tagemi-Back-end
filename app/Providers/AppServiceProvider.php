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
                Limit::perMinute(10)->by(strtolower((string) $request->input('email')) . '|' . $request->ip()),
                Limit::perMinute(150)->by($request->ip()),
            ];
        });

        RateLimiter::for('register', function (Request $request) {
            return Limit::perHour(10)->by($request->ip());
        });

        // Public forms (booking, evaluation, contact, volunteer). Generous on
        // purpose: in Algeria many users share a carrier-grade NAT IP, and on
        // visit day hundreds of patients fill the evaluation on the venue Wi-Fi.
        RateLimiter::for('public-forms', function (Request $request) {
            return Limit::perMinute(120)->by($request->ip());
        });

        // «دوري» page: each open page polls every 30 s. Limited per person
        // (number or phone) so a whole hall on one IP keeps working.
        RateLimiter::for('my-turn', function (Request $request) {
            $who = $request->input('patient_id') . '|' . preg_replace('/\D/', '', (string) $request->input('phone'));

            return [
                Limit::perMinute(12)->by($request->ip() . '|' . $who),
                Limit::perMinute(1200)->by($request->ip()),
            ];
        });

        // Health data behind three answers: guessing stays slow per patient
        // number, while many patients on one Wi-Fi can still read their own
        RateLimiter::for('my-data', function (Request $request) {
            return [
                Limit::perMinute(5)->by($request->ip() . '|' . $request->input('patient_id')),
                Limit::perMinute(30)->by($request->ip()),
                Limit::perDay(300)->by($request->ip()),
            ];
        });
    }
}
