<?php

namespace App\Services;

use App\Models\MonetaryContribution;
use App\Models\MonetaryGift;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class MonetaryService
{
    public function __construct(
        private AssetService $assetService
    ) {
    }

    /* ── List ── */

    public function getUserMonetaryGifts(User $user): LengthAwarePaginator
    {
        return MonetaryGift::where('user_id', $user->id)
            ->withCount('successfulContributions')
            ->latest()
            ->paginate(20);
    }

    /* ── Create ── */

    public function create(User $user, array $data): MonetaryGift
    {
        $coverPhotoUrl = null;

        if (!empty($data['cover_photo']) && $data['cover_photo'] instanceof UploadedFile) {
            $coverPhotoUrl = $this->assetService->upload($data['cover_photo'], 'monetary');
        }

        return MonetaryGift::create([
            'user_id' => $user->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'target_amount' => $data['target_amount'],
            'cover_photo' => $coverPhotoUrl,
        ]);
    }

    /* ── Update ── */

    public function update(MonetaryGift $gift, array $data): MonetaryGift
    {
        if (!empty($data['cover_photo']) && $data['cover_photo'] instanceof UploadedFile) {
            $data['cover_photo'] = $this->assetService->replace(
                $gift->cover_photo,
                $data['cover_photo'],
                'monetary'
            );
        } else {
            unset($data['cover_photo']);
        }

        $gift->update($data);
        return $gift->fresh();
    }

    /* ── Delete ── */

    public function delete(MonetaryGift $gift): void
    {
        if ($gift->cover_photo) {
            $this->assetService->delete($gift->cover_photo);
        }
        $gift->delete();
    }

    /* ── Public ── */

    public function findByShareToken(string $token): ?MonetaryGift
    {
        return MonetaryGift::where('share_token', $token)
            ->where('is_active', true)
            ->with([
                'user:id,first_name,last_name',
                'successfulContributions' => function ($q) {
                    $q->select(['id', 'monetary_gift_id', 'donor_name', 'amount', 'is_anonymous', 'created_at'])
                        ->latest()
                        ->limit(10);
                }
            ])
            ->first();
    }

    /* ── Contribute ── */

    public function contribute(MonetaryGift $gift, array $data): array
    {
        return DB::transaction(function () use ($gift, $data) {

            // Generate unique reference
            $reference = 'CHR_MON_' . $gift->id . '_' . uniqid();

            // Create pending contribution
            $contribution = MonetaryContribution::create([
                'monetary_gift_id' => $gift->id,
                'donor_name' => $data['donor_name'],
                'donor_email' => $data['donor_email'],
                'donor_phone' => $data['donor_phone'] ?? null,
                'amount' => $data['amount'],
                'bvn' => $data['bvn'] ?? null,
                'payment_method' => $data['payment_method'],
                'payment_status' => 'pending',
                'payment_reference' => $reference,
                'is_anonymous' => $data['is_anonymous'] ?? false,
            ]);

            // Initialize payment via gateway
            $gateway = app(\App\Services\GatewayService::class);

            $payment = $gateway->initializePayment([
                'email' => $data['donor_email'],
                'name' => $data['donor_name'],
                'amount' => $data['amount'],
                'reference' => $reference,
                'callback_url' => config('app.frontend_url') . '/payment/callback',
                'metadata' => [
                    'contribution_id' => $contribution->id,
                    'gift_id' => $gift->id,
                    'type' => 'monetary_contribution',
                ],
            ]);

            if (!$payment['success']) {
                throw new \Exception($payment['message'] ?? 'Payment initialization failed.');
            }

            return [
                'contribution_id' => $contribution->id,
                'amount' => $contribution->amount,
                'payment_method' => $contribution->payment_method,
                'reference' => $reference,
                'payment_url' => $payment['payment_url'],
            ];
        });
    }


    /* ── Confirm payment ── */

    public function confirmPayment(string $reference, string $status, array $meta = []): bool
    {
        return DB::transaction(function () use ($reference, $status, $meta) {
            $contribution = MonetaryContribution::where('payment_reference', $reference)->first();

            if (!$contribution)
                return false;

            $contribution->update([
                'payment_status' => $status,
                'payment_meta' => $meta,
            ]);

            if ($status === 'successful') {
                $gift = $contribution->monetaryGift;
                $gift->increment('amount_raised', $contribution->amount);

                // Credit owner's wallet
                $wallet = $gift->user->wallet;
                if ($wallet) {
                    $wallet->increment('balance', $contribution->amount);
                    $wallet->increment('total_received', $contribution->amount);

                    $wallet->transactions()->create([
                        'user_id' => $wallet->user_id,
                        'type' => 'credit',
                        'amount' => $contribution->amount,
                        'description' => "Donation for {$gift->title}",
                        'reference' => $reference,
                        'status' => 'successful',
                    ]);

                    $notificationService = app(\App\Services\NotificationService::class);
                    $notificationService->create(
                        $gift->user,
                        'contribution',
                        'New donation received!',
                        ($contribution->is_anonymous ? 'Someone' : $contribution->donor_name) . " donated ₦" . number_format($contribution->amount, 2) . " to {$gift->title}.",
                        "/dashboard/monetary/{$gift->id}",
                        '💰'
                    );
                }
            }

            return true;
        });
    }

    /* ── Get contributors ── */

    public function getContributors(MonetaryGift $gift): \Illuminate\Database\Eloquent\Collection
    {
        return $gift->successfulContributions()
            ->select([
                'id',
                'donor_name',
                'amount',
                'is_anonymous',
                'created_at',
                DB::raw("CASE WHEN is_anonymous = true THEN 'Anonymous' ELSE donor_name END as display_name"),
            ])
            ->latest()
            ->get();
    }
}
