<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\GiftRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminRegistryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = GiftRegistry::with('user:id,first_name,last_name,email')
            ->withCount('gifts')
            ->latest();

        if ($search = $request->query('search')) {
            $query->where('name', 'ilike', "%{$search}%");
        }

        if ($request->query('public') !== null) {
            $query->where('is_public', $request->query('public') === 'true');
        }

        return ApiResponse::success('Registries retrieved.', $query->paginate(20));
    }

    public function show(GiftRegistry $registry): JsonResponse
    {
        $registry->load(['user:id,first_name,last_name,email', 'gifts']);
        return ApiResponse::success('Registry retrieved.', $registry);
    }

    public function destroy(GiftRegistry $registry): JsonResponse
    {
        $registry->delete();
        return ApiResponse::success('Registry deleted successfully.');
    }
}