<?php

namespace App\Http\Middleware;

use App\Helpers\ApiResponse;
use Closure;
use Illuminate\Http\Request;

/**
 * Vendor portal routes. `vendor` needs a vendor profile; `vendor:approved`
 * needs it approved (selling and orders).
 */
class EnsureVendor
{
    public function handle(Request $request, Closure $next, string $level = 'any')
    {
        $vendor = $request->user()?->vendor()->first();

        if (!$vendor) {
            return ApiResponse::error('Please register as a vendor first.', statusCode: 403);
        }
        if ($level === 'approved' && !$vendor->isApproved()) {
            return ApiResponse::error(match ($vendor->status) {
                'pending' => 'Your vendor account is waiting for approval.',
                'suspended' => 'Your vendor account is suspended. Please contact support.',
                default => 'Your vendor account isn’t approved.',
            }, statusCode: 403);
        }

        $request->attributes->set('vendor', $vendor);
        return $next($request);
    }
}
