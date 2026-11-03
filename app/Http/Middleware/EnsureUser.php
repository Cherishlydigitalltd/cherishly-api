<?php

namespace App\Http\Middleware;

use App\Helpers\ApiResponse;
use Closure;
use Illuminate\Http\Request;

/** Celebrant/vendor routes: the token must belong to a Cherishly account, not an admin. */
class EnsureUser
{
    public function handle(Request $request, Closure $next)
    {
        if (!($request->user() instanceof \App\Models\User)) {
            return ApiResponse::unauthorized('Please log in.');
        }
        return $next($request);
    }
}
