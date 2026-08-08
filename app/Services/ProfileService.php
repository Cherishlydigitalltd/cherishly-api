<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;

class ProfileService
{
    public function __construct(
        private AssetService $assetService,
        private OtpService $otpService
    ) {}

    /* ── Update profile ── */

    public function update(User $user, array $data): User
    {
        $user->update(array_filter([
            'first_name' => $data['first_name'] ?? null,
            'last_name'  => $data['last_name'] ?? null,
            'phone'      => $data['phone'] ?? null,
        ], fn($v) => $v !== null));

        return $user->fresh();
    }

    /* ── Update avatar ── */

    public function updateAvatar(User $user, UploadedFile $file): User
    {
        $url = $this->assetService->replace(
            $user->avatar,
            $file,
            'avatars'
        );

        $user->update(['avatar' => $url]);

        return $user->fresh();
    }

    /* ── Delete avatar ── */

    public function deleteAvatar(User $user): User
    {
        if ($user->avatar) {
            $this->assetService->delete($user->avatar);
            $user->update(['avatar' => null]);
        }

        return $user->fresh();
    }

    /* ── Change password ── */

    public function changePassword(User $user, string $currentPassword, string $newPassword): array
    {
        if (!Hash::check($currentPassword, $user->password)) {
            return ['success' => false, 'message' => 'Current password is incorrect.'];
        }

        $user->update(['password' => $newPassword]);

        // Revoke all other tokens except current
        $currentTokenId = $user->currentAccessToken()->id;
        $user->tokens()->where('id', '!=', $currentTokenId)->delete();

        return ['success' => true, 'message' => 'Password changed successfully.'];
    }

    /* ── Format user for response ── */

    public function formatUser(User $user): array
    {
        return [
            'id'                => $user->id,
            'first_name'        => $user->first_name,
            'last_name'         => $user->last_name,
            'full_name'         => $user->full_name,
            'email'             => $user->email,
            'phone'             => $user->phone,
            'avatar'            => $user->avatar,
            'is_email_verified' => $user->is_email_verified,
            'is_phone_verified' => $user->is_phone_verified,
            'created_at'        => $user->created_at,
        ];
    }
}
