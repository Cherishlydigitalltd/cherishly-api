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