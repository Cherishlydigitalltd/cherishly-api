<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Super admins manage admin accounts. You can't deactivate or reset yourself,
 * so there is always at least one active super admin.
 */
class AdminAccountController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success('Admins retrieved.', Admin::orderBy('first_name')->orderBy('last_name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $request->merge(['email' => strtolower(trim((string) $request->email))]);

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', Rule::unique('admins', 'email')],
            'password' => ['required', 'string', 'min:10'],
            'role' => ['required', Rule::in(['admin', 'super_admin'])],
        ]);

        return ApiResponse::success('Admin created.', Admin::create($data + ['is_active' => true]), 201);
    }

    public function setStatus(Request $request, Admin $account): JsonResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);

        if ($account->is($request->user())) {
            return ApiResponse::error('You can\'t deactivate your own account.', null, 422);
        }

        $account->update(['is_active' => $data['is_active']]);

        if (!$data['is_active']) {
            $account->tokens()->delete();
        }

        return ApiResponse::success($data['is_active'] ? 'Admin activated.' : 'Admin deactivated.', $account);
    }

    /** Sets a new password for another admin and signs them out everywhere. */
    public function resetPassword(Request $request, Admin $account): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string', 'min:10']]);

        if ($account->is($request->user())) {
            return ApiResponse::error('Use the change password form for your own account.', null, 422);
        }

        $account->update(['password' => $data['password']]);
        $account->tokens()->delete();

        return ApiResponse::success('Password reset.');
    }
}
