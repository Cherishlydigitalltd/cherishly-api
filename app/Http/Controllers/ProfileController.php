<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Requests\Profile\ChangePasswordRequest;
use App\Http\Requests\Profile\UpdateAvatarRequest;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Services\ProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function __construct(
        private ProfileService $profileService
    ) {}

    /**
     * GET /api/profile
     * Get current user profile
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user()->load('wallet');

        return ApiResponse::success('Profile retrieved.', [
            'user' => $this->profileService->formatUser($user),
            'wallet' => [
                'balance'        => $user->wallet?->balance ?? 0,
                'total_received' => $user->wallet?->total_received ?? 0,
            ],
        ]);
    }

    /**
     * PUT /api/profile
     * Update name and phone
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $this->profileService->update($request->user(), $request->validated());

        return ApiResponse::success('Profile updated successfully.', [
            'user' => $this->profileService->formatUser($user),
        ]);
    }

    /**
     * POST /api/profile/avatar
     * Upload/replace avatar
     */
    public function updateAvatar(UpdateAvatarRequest $request): JsonResponse
    {
        $user = $this->profileService->updateAvatar(
            $request->user(),
            $request->file('avatar')
        );

        return ApiResponse::success('Avatar updated successfully.', [
            'avatar' => $user->avatar,
        ]);
    }

    /**
     * DELETE /api/profile/avatar
     * Remove avatar
     */
    public function deleteAvatar(Request $request): JsonResponse
    {
        $this->profileService->deleteAvatar($request->user());

        return ApiResponse::success('Avatar removed successfully.');
    }

    /**
     * POST /api/profile/change-password
     * Change password while logged in
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $result = $this->profileService->changePassword(
            $request->user(),
            $request->current_password,
            $request->password
        );

        if (!$result['success']) {
            return ApiResponse::error($result['message'], null, 400);
        }

        return ApiResponse::success($result['message']);
    }
}
