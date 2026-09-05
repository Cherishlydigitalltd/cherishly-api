<?php

namespace App\Http\Middleware;

use App\Helpers\ApiResponse;
use Closure;
use Illuminate\Http\Request;

class AdminMiddleware
{
    public function handle(Request $request, Closure $next, string $role = 'admin')
    {
        $admin = $request->user();

        if (!$admin || !($admin instanceof \App\Models\Admin)) {
            return ApiResponse::unauthorized('Admin access required.');
        }

        if (!$admin->is_active) {
            return ApiResponse::unauthorized('Your admin account has been suspended.');
        }

        if ($role === 'super_admin' && !$admin->isSuperAdmin()) {
            return ApiResponse::error('Super admin access required.', null, 403);
        }

        return $next($request);
    }
}