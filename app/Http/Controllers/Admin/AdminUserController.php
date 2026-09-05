<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminUserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = User::withCount(['registries', 'monetaryGifts'])
            ->latest();

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'ilike', "%{$search}%")
                    ->orWhere('last_name', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%");
            });
        }

        if ($status = $request->query('status')) {
            $query->where('is_active', $status === 'active');
        }

        return ApiResponse::success('Users retrieved.', $query->paginate(20));
    }

    public function show(User $user): JsonResponse
    {
        $user->load(['registries', 'monetaryGifts', 'wallet']);
        $user->loadCount(['registries', 'monetaryGifts']);
        return ApiResponse::success('User retrieved.', $user);
    }

    public function suspend(User $user): JsonResponse
    {
        $user->update(['is_active' => false]);
        return ApiResponse::success('User suspended successfully.');
    }

    public function activate(User $user): JsonResponse
    {
        $user->update(['is_active' => true]);
        return ApiResponse::success('User activated successfully.');
    }

    public function destroy(User $user): JsonResponse
    {
        $user->delete();
        return ApiResponse::success('User deleted successfully.');
    }
}