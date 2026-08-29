<?php

namespace App\Services;

use App\Models\MemoryWall;
use App\Models\Wish;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;

class MemoryWallService
{
    public function __construct(
        private AssetService $assetService
    ) {}

    /* ── List ── */

    public function getUserWalls(User $user): LengthAwarePaginator
    {
        return MemoryWall::where('user_id', $user->id)
            ->withCount('wishes')
            ->latest()
            ->paginate(20);
    }

    /* ── Create ── */

    public function create(User $user, array $data): MemoryWall
    {
        $coverPhotoUrl = null;

        if (!empty($data['cover_photo']) && $data['cover_photo'] instanceof UploadedFile) {
            $coverPhotoUrl = $this->assetService->upload($data['cover_photo'], 'walls');
        }

        return MemoryWall::create([
            'user_id'     => $user->id,
            'title'       => $data['title'],
            'description' => $data['description'] ?? null,
            'cover_photo' => $coverPhotoUrl,
            'is_active'   => $data['is_active'] ?? true,
        ]);
    }

    /* ── Update ── */

    public function update(MemoryWall $wall, array $data): MemoryWall
    {
        if (!empty($data['cover_photo']) && $data['cover_photo'] instanceof UploadedFile) {
            $data['cover_photo'] = $this->assetService->replace(
                $wall->cover_photo,
                $data['cover_photo'],
                'walls'
            );
        } else {
            unset($data['cover_photo']);
        }

        $wall->update($data);
        return $wall->fresh();
    }

    /* ── Delete ── */

    public function delete(MemoryWall $wall): void
    {
        if ($wall->cover_photo) {
            $this->assetService->delete($wall->cover_photo);
        }
        $wall->delete();
    }

    /* ── Get wishes (paginated) ── */

    public function getWishes(MemoryWall $wall): LengthAwarePaginator
    {
        return $wall->wishes()
            ->latest()
            ->paginate(20);
    }

    /* ── Delete a wish ── */

    public function deleteWish(Wish $wish): void
    {
        $wish->delete();
    }

    /* ── Public: get by token ── */

    public function findByToken(string $token): ?MemoryWall
    {
        return MemoryWall::where('share_token', $token)
            ->where('is_active', true)
            ->first();
    }

    /* ── Public: add wish ── */

    public function addWish(MemoryWall $wall, array $data): Wish
    {
        return Wish::create([
            'wall_id'      => $wall->id,
            'name'         => $data['name'],
            'message'      => $data['message'],
            'is_anonymous' => $data['is_anonymous'] ?? false,
        ]);
    }

    /* ── Public: get wishes ── */

    public function getPublicWishes(MemoryWall $wall): LengthAwarePaginator
    {
        return $wall->wishes()
            ->latest()
            ->paginate(20);
    }
}
