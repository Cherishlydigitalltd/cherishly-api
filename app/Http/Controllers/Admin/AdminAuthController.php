<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AdminAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $admin = Admin::where('email', strtolower($request->email))->first();

        if (!$admin || !Hash::check($request->password, $admin->password)) {
            return ApiResponse::error('Invalid credentials.', null, 401);
        }

        if (!$admin->is_active) {
            return ApiResponse::error('Your account has been suspended.', null, 403);
        }

        $admin->update(['last_login_at' => now()]);
        $token = $admin->createToken('admin-token')->plainTextToken;

        return ApiResponse::success('Login successful.', [
            'token' => $token,
            'admin' => $admin,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        return ApiResponse::success('Logged out successfully.');
    }

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success('Admin retrieved.', $request->user());
    }

    /** Changes your own password and signs out your other devices. */
    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:10', 'confirmed', 'different:current_password'],
        ]);

        $admin = $request->user();

        if (!Hash::check($data['current_password'], $admin->password)) {
            return ApiResponse::error('Your current password is incorrect.', null, 422);
        }

        $admin->update(['password' => $data['password']]);
        $admin->tokens()->where('id', '!=', $admin->currentAccessToken()->id)->delete();

        return ApiResponse::success('Password changed.');
    }
}