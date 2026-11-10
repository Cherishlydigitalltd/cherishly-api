<?php

namespace App\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Events\TokenAuthenticated;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Gate::policy(\App\Models\Event::class, \App\Policies\EventPolicy::class);

        // Customers stay signed in while they keep using Cherishly: each
        // use pushes their sign-in expiry back to the full lifetime (at
        // most one write a day). Admin and password-reset tokens keep
        // their fixed expiry.
        Event::listen(TokenAuthenticated::class, function (TokenAuthenticated $event) {
            $token = $event->token;
            if ($token->name !== 'auth_token' || !$token->tokenable instanceof \App\Models\User) {
                return;
            }
            $days = config('sanctum.user_token_days');
            if (!$token->expires_at || $token->expires_at->lt(now()->addDays($days - 1))) {
                $token->forceFill(['expires_at' => now()->addDays($days)])->save();
            }
        });
    }
}
