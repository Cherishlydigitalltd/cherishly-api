<?php

if (!function_exists('setting')) {
    function setting(string $key, mixed $default = null): mixed
    {
        return \Illuminate\Support\Facades\Cache::remember(
            "setting_{$key}",
            now()->addHours(24),
            fn() => \App\Models\Setting::where('key', $key)->value('value') ?? $default
        );
    }
}