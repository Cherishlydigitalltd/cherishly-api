<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use App\Helpers\ApiResponse;

class RateLimitServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Login — 5 attempts per minute per IP
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip())
                ->response(fn() => ApiResponse::error(
                    'Too many login attempts. Please try again in a minute.',
                    null,
                    429
                ));
        });

        // OTP — 3 requests per minute per IP
        RateLimiter::for('otp', function (Request $request) {
            return Limit::perMinute(3)->by($request->ip())
                ->response(fn() => ApiResponse::error(
                    'Too many OTP requests. Please wait before trying again.',
                    null,
                    429
                ));
        });

        // General API — 120/min for auth users, 30/min for guests
        RateLimiter::for('api', function (Request $request) {
            return $request->user()
                ? Limit::perMinute(120)->by($request->user()->id)
                : Limit::perMinute(30)->by($request->ip());
        });

        // Withdraw — 5 per hour per user
        RateLimiter::for('withdraw', function (Request $request) {
            return Limit::perHour(5)->by($request->user()?->id ?? $request->ip())
                ->response(fn() => ApiResponse::error(
                    'Too many withdrawal attempts. Please try again later.',
                    null,
                    429
                ));
        });

        // Digital spray. Keyed by the guest's spray token where there is one,
        // because a whole venue can be on one Wi-Fi (one IP).
        $sprayKey = fn (Request $request) => 'spray:' . ($request->header('X-Spray-Token') ?: $request->ip());
        $tooMany = fn () => ApiResponse::error('Slow down a little and try again.', null, 429);
        RateLimiter::for('spray', fn (Request $request) => Limit::perMinute(240)->by($sprayKey($request))->response($tooMany));
        RateLimiter::for('spray-topup', fn (Request $request) => Limit::perMinute(10)->by($sprayKey($request))->response($tooMany));
        RateLimiter::for('spray-join', fn (Request $request) => Limit::perMinute(60)->by('spray-join:' . $request->ip())->response($tooMany));
        RateLimiter::for('spray-screen', fn (Request $request) => Limit::perMinute(300)->by('spray-screen:' . $request->ip())->response($tooMany));

        // Contributions — 10 per minute per IP
        RateLimiter::for('contribution', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip())
                ->response(fn() => ApiResponse::error(
                    'Too many contribution attempts. Please try again shortly.',
                    null,
                    429
                ));
        });

        // Contact form — 5 per hour per IP
        RateLimiter::for('contact', function (Request $request) {
            return Limit::perHour(5)->by($request->ip())
                ->response(fn() => ApiResponse::error(
                    'Too many contact requests. Please try again later.',
                    null,
                    429
                ));
        });
    }
}