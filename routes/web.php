<?php

use Illuminate\Support\Facades\Route;

// The API has no web pages. Answer the root simply (it used to render an
// unused view and could fail with a 500, which Google reported), and tell
// search engines not to index this subdomain.
Route::get('/', fn () => response()->json([
    'name' => 'Cherishly API',
    'website' => 'https://cherishlyng.com',
])->header('X-Robots-Tag', 'noindex, nofollow'));
