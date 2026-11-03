<?php

namespace App\Services;

use App\Models\Contribution;
use App\Models\VendorProduct;
use App\Models\Gift;
use App\Models\GiftRegistry;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class GiftRegistryService
{
    public function __construct(
        private AssetService $assetService
    ) {
    }

    /* ────────────────────────────────────────────
     | REGISTRY
     ──────────────────────────────────────────── */

    public function getUserRegistries(User $user): LengthAwarePaginator
    {
        return GiftRegistry::where('user_id', $user->id)
            ->withCount('gifts')
            ->latest()
            ->paginate(20);
    }

    public function create(User $user, array $data): GiftRegistry
    {
        $coverPhotoUrl = null;

        if (!empty($data['cover_photo']) && $data['cover_photo'] instanceof UploadedFile) {
            $coverPhotoUrl = $this->assetService->upload($data['cover_photo'], 'registries');
        }

        return GiftRegistry::create([
            'user_id' => $user->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'cover_photo' => $coverPhotoUrl,
            'is_public' => $data['is_public'] ?? true,
        ]);
    }

    public function update(GiftRegistry $registry, array $data): GiftRegistry
    {
        if (!empty($data['cover_photo']) && $data['cover_photo'] instanceof UploadedFile) {
            $data['cover_photo'] = $this->assetService->replace(
                $registry->cover_photo,
                $data['cover_photo'],
                'registries'
            );
        } else {
            unset($data['cover_photo']);
        }

        $registry->update($data);
        return $registry->fresh();
    }

    public function delete(GiftRegistry $registry): void
    {
        // Delete cover photo from asset server
        if ($registry->cover_photo) {
            $this->assetService->delete($registry->cover_photo);
        }

        // Delete all gift images
        foreach ($registry->gifts as $gift) {
            if ($gift->image) {
                $this->assetService->delete($gift->image);
            }
        }

        $registry->delete();
    }

    public function findByShareToken(string $token): ?GiftRegistry
    {
        return GiftRegistry::where('share_token', $token)
            // Remove ->where('is_public', true)
            ->with([
                'gifts' => function ($q) {
                    $q->withCount('successfulContributions')->with('vendor:id,business_name,logo');
                }
            ])
            ->first();
    }
    /* ────────────────────────────────────────────
     | GIFTS
     ──────────────────────────────────────────── */

    public function getGifts(GiftRegistry $registry): \Illuminate\Database\Eloquent\Collection
    {
        return $registry->gifts()
            ->withCount('successfulContributions')
            ->with(['vendor:id,business_name,logo', 'vendorOrder' => fn ($q) => $q->select('vendor_orders.id', 'vendor_orders.gift_id', 'vendor_orders.status', 'vendor_orders.reference')])
            ->latest()
            ->get();
    }

    public function addGift(GiftRegistry $registry, array $data): Gift
    {
        // The catalog is vendor items now; older apps still send catalog_gift_id.
        $productId = $data['vendor_product_id'] ?? $data['catalog_gift_id'] ?? null;
        if ($productId) {
            return $this->addVendorGift($registry, (int) $productId);
        }

        $imageUrl = null;

        if (!empty($data['image']) && $data['image'] instanceof UploadedFile) {
            $imageUrl = $this->assetService->upload($data['image'], 'gifts');
        }

        return Gift::create([
            'registry_id' => $registry->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'price' => $data['price'],
            'quantity' => $data['quantity'] ?? 1,
            'category' => $data['category'] ?? null,
            'image' => $imageUrl,
            'type' => $data['type'] ?? 'physical',
            'allow_cash_contribution' => $data['allow_cash_contribution'] ?? false,
        ]);
    }

    /** A vendor's item, copied as it is now: the vendor's price, not the client's. */
    private function addVendorGift(GiftRegistry $registry, int $productId): Gift
    {
        $product = VendorProduct::with('vendor')->find($productId);
        if (!$product || !$product->isListed()) {
            throw new \RuntimeException('This item is no longer available.');
        }

        return Gift::create([
            'registry_id' => $registry->id,
            'name' => $product->name,
            'description' => $product->description,
            'price' => $product->price,
            'quantity' => 1,
            'category' => $product->category,
            'image' => $product->image,
            'type' => 'physical',
            'allow_cash_contribution' => true,
            'vendor_product_id' => $product->id,
            'vendor_id' => $product->vendor_id,
        ]);
    }

    public function updateGift(Gift $gift, array $data): Gift
    {
        // A vendor item's name, price and photo are the vendor's.
        if ($gift->is_vendor_item) {
            $data = array_intersect_key($data, array_flip(['description']));
        }

        if (!empty($data['image']) && $data['image'] instanceof UploadedFile) {
            $data['image'] = $this->assetService->replace($gift->image, $data['image'], 'gifts');
        } else {
            unset($data['image']);
        }

        $gift->update($data);
        return $gift->fresh();
    }

    public function deleteGift(Gift $gift): void
    {
        if ($gift->is_vendor_item) {
            if ($gift->fulfilment === 'order' || ($gift->fulfilment === null && (float) $gift->amount_contributed > 0)) {
                throw new \RuntimeException('Guests have paid toward this gift. Take the money as cash first, then you can remove it.');
            }
            // The photo belongs to the vendor's listing; leave it.
            $gift->delete();
            return;
        }

        if ($gift->image) {
            $this->assetService->delete($gift->image);
        }
        $gift->delete();
    }

    /* ────────────────────────────────────────────
     | CONTRIBUTIONS
     ──────────────────────────────────────────── */

    public function contribute(Gift $gift, array $data): array
    {
        return DB::transaction(function () use ($gift, $data) {

            if ($gift->is_fully_funded && $gift->type === 'physical') {
                throw new \RuntimeException('This gift has already been fully funded.');
            }

            // ₦100 minimum, except paying off the last few naira of a vendor item.
            $isTheRest = $gift->is_vendor_item && abs((float) $data['amount'] - $gift->amount_remaining) < 0.01;
            if ((float) $data['amount'] < 100 && !$isTheRest) {
                throw new \RuntimeException('Minimum contribution amount is ₦100.');
            }

            if ($gift->is_vendor_item) {
                if (!$gift->isCollecting()) {
                    throw new \RuntimeException('This gift is no longer taking contributions.');
                }
                if ((float) $data['amount'] > $gift->amount_remaining) {
                    throw new \RuntimeException('Only ₦' . number_format($gift->amount_remaining, 2) . ' is left to pay for this gift.');
                }
            }

            $reference = 'CHR_GIFT_' . $gift->id . '_' . uniqid();

            // ── Calculate fee ──
            $feeService = app(\App\Services\FeeService::class);
            $owner = $gift->registry->user;
            // Vendor items must collect their full price, so the guest pays the fee.
            $fee = $feeService->calculate((float) $data['amount'], $owner, $gift->is_vendor_item ? 'gifter' : null);

            $contribution = Contribution::create([
                'gift_id' => $gift->id,
                'donor_name' => $data['donor_name'],
                'donor_email' => $data['donor_email'],
                'donor_phone' => $data['donor_phone'] ?? null,
                'amount' => $data['amount'],
                'gross_amount' => $fee['gross_amount'],
                'net_amount' => $fee['net_amount'],
                'fee_amount' => $fee['fee_amount'],
                'fee_rate' => $fee['fee_rate'],
                'fee_bearer' => $fee['fee_bearer'],
                'bvn' => $data['bvn'] ?? null,
                'payment_method' => $data['payment_method'],
                'payment_status' => 'pending',
                'payment_reference' => $reference,
                'is_anonymous' => $data['is_anonymous'] ?? false,
            ]);

            $gateway = app(\App\Services\GatewayService::class);
            $payment = $gateway->initializePayment([
                'email' => $data['donor_email'],
                'name' => $data['donor_name'],
                'amount' => $fee['charge_amount'], // ← charge gross amount to Paystack
                'reference' => $reference,
                'callback_url' => config('app.frontend_url') . '/payment/callback',
                'metadata' => [
                    'contribution_id' => $contribution->id,
                    'gift_id' => $gift->id,
                    'type' => 'gift_contribution',
                ],
            ]);

            if (!$payment['success']) {
                throw new \RuntimeException($payment['message'] ?? 'Payment initialization failed.');
            }

            return [
                'contribution_id' => $contribution->id,
                'amount' => $data['amount'],
                'gross_amount' => $fee['gross_amount'],
                'fee_amount' => $fee['fee_amount'],
                'fee_bearer' => $fee['fee_bearer'],
                'payment_method' => $contribution->payment_method,
                'reference' => $reference,
                'payment_url' => $payment['payment_url'],
            ];
        });
    }

    public function confirmPayment1(string $reference, string $status, array $meta = []): bool
    {
        return DB::transaction(function () use ($reference, $status, $meta) {
            $contribution = Contribution::where('payment_reference', $reference)->first();

            if (!$contribution)
                return false;

            $contribution->update([
                'payment_status' => $status,
                'payment_meta' => $meta,
            ]);

            // Update gift amount if payment successful
            if ($status === 'successful') {
                $gift = $contribution->gift;
                $gift->increment('amount_contributed', $contribution->amount);

                // Credit registry owner's wallet
                $wallet = $gift->registry->user->wallet;
                if ($wallet) {
                    $wallet->increment('balance', $contribution->amount);
                    $wallet->increment('total_received', $contribution->amount);

                    // Log transaction
                    $wallet->transactions()->create([
                        'user_id' => $wallet->user_id,
                        'type' => 'credit',
                        'amount' => $contribution->amount,
                        'description' => "Contribution for {$gift->name}",
                        'reference' => $reference,
                        'status' => 'successful',
                    ]);

                    $notificationService = app(\App\Services\NotificationService::class);
                    $notificationService->create(
                        $gift->registry->user,
                        'contribution',
                        'New contribution received!',
                        ($contribution->is_anonymous ? 'Someone' : $contribution->donor_name) . " contributed ₦" . number_format($contribution->amount, 2) . " to {$gift->name}.",
                        "/dashboard/registry/{$gift->registry_id}/gift/{$gift->id}",
                        '🎁'
                    );


                    // ── Send email notification ──
                    $owner = $gift->registry->user;
                    $donorName = $contribution->is_anonymous ? 'Anonymous' : $contribution->donor_name;
                    \App\Jobs\SendContributionEmail::dispatch(
                        $owner,
                        $donorName,
                        (float) $contribution->amount,
                        $gift->name,
                        $gift->registry->name,
                        config('app.frontend_url') . '/dashboard/registry/' . $gift->registry_id . '/gift/' . $gift->id,
                    );

                }
            }

            return true;
        });
    }

    public function confirmPaymentAlt(string $reference, string $status, array $meta = []): bool
    {
        return DB::transaction(function () use ($reference, $status, $meta) {
            $contribution = Contribution::where('payment_reference', $reference)->first();

            if (!$contribution)
                return false;

            $contribution->update([
                'payment_status' => $status,
                'payment_meta' => $meta,
            ]);

            if ($status === 'successful') {
                $gift = $contribution->gift;
                $owner = $gift->registry->user;

                // Use net_amount (what owner receives after fee) — fallback to amount if not set
                $creditAmount = $contribution->net_amount ?? $contribution->amount;

                $gift->increment('amount_contributed', $creditAmount);

                // Credit registry owner's wallet
                $wallet = $owner->wallet;
                if ($wallet) {
                    $wallet->increment('balance', $creditAmount);
                    $wallet->increment('total_received', $creditAmount);

                    // Log transaction with fee breakdown
                    $wallet->transactions()->create([
                        'user_id' => $wallet->user_id,
                        'type' => 'credit',
                        'amount' => $creditAmount,
                        'description' => "Contribution for {$gift->name}" .
                            ($contribution->fee_amount > 0
                                ? " (fee: ₦" . number_format($contribution->fee_amount, 2) . " paid by {$contribution->fee_bearer})"
                                : ''),
                        'reference' => $reference,
                        'status' => 'successful',
                    ]);

                    // In-app notification
                    $notificationService = app(\App\Services\NotificationService::class);
                    $notificationService->create(
                        $owner,
                        'contribution',
                        'New contribution received!',
                        ($contribution->is_anonymous ? 'Someone' : $contribution->donor_name) .
                        " contributed ₦" . number_format($contribution->gross_amount ?? $contribution->amount, 2) .
                        " to {$gift->name}." .
                        ($contribution->fee_bearer === 'owner' && $contribution->fee_amount > 0
                            ? " (₦" . number_format($contribution->fee_amount, 2) . " fee deducted)"
                            : ''),
                        "/dashboard/registry/{$gift->registry_id}/gift/{$gift->id}",
                        '🎁'
                    );

                    // Email notification
                    $donorName = $contribution->is_anonymous ? 'Anonymous' : $contribution->donor_name;
                    \App\Jobs\SendContributionEmail::dispatch(
                        $owner,
                        $donorName,
                        (float) $creditAmount,
                        $gift->name,
                        $gift->registry->name,
                        config('app.frontend_url') . '/dashboard/registry/' . $gift->registry_id . '/gift/' . $gift->id,
                    );
                }
            }

            return true;
        });
    }

    public function confirmPayment(string $reference, string $status, array $meta = []): bool
    {
        return DB::transaction(function () use ($reference, $status, $meta) {
            $contribution = Contribution::where('payment_reference', $reference)->lockForUpdate()->first();

            if (!$contribution)
                return false;

            // Gateways resend webhooks. Once a payment has been credited, a
            // repeat must not credit the wallet (or the gift) a second time.
            if ($contribution->payment_status === 'successful') {
                return true;
            }

            $contribution->update([
                'payment_status' => $status,
                'payment_meta' => $meta,
            ]);

            if ($status === 'successful' && $contribution->gift->is_vendor_item) {
                // Held for the vendor until delivery, not paid into the wallet.
                app(MarketplaceService::class)->recordPayment($contribution->gift, $contribution);
                return true;
            }

            if ($status === 'successful') {
                $gift = $contribution->gift;
                $owner = $gift->registry->user;

                $creditAmount = $contribution->net_amount ?? $contribution->amount;
                $availableAt = now()->addDay(); // T+1

                $gift->increment('amount_contributed', $creditAmount);

                $wallet = $owner->wallet;
                if ($wallet) {
                    // Increment total balance and pending — NOT available_balance yet
                    $wallet->increment('balance', $creditAmount);
                    $wallet->increment('total_received', $creditAmount);
                    $wallet->increment('pending_settlement', $creditAmount);

                    // Log transaction with T+1 settlement time
                    $wallet->transactions()->create([
                        'user_id' => $wallet->user_id,
                        'type' => 'credit',
                        'amount' => $creditAmount,
                        'description' => "Contribution for {$gift->name}" .
                            ($contribution->fee_amount > 0
                                ? " (fee: ₦" . number_format($contribution->fee_amount, 2) . " paid by {$contribution->fee_bearer})"
                                : ''),
                        'reference' => $reference,
                        'status' => 'successful',
                        'available_at' => $availableAt,
                    ]);

                    // In-app notification
                    $notificationService = app(\App\Services\NotificationService::class);
                    $notificationService->create(
                        $owner,
                        'contribution',
                        'New contribution received!',
                        ($contribution->is_anonymous ? 'Someone' : $contribution->donor_name) .
                        " contributed ₦" . number_format($contribution->gross_amount ?? $contribution->amount, 2) .
                        " to {$gift->name}." .
                        ($contribution->fee_bearer === 'owner' && $contribution->fee_amount > 0
                            ? " (₦" . number_format($contribution->fee_amount, 2) . " fee deducted)"
                            : '') .
                        " Funds available for withdrawal by " . $availableAt->format('M j, Y') . ".",
                        "/dashboard/registry/{$gift->registry_id}/gift/{$gift->id}",
                        '🎁'
                    );

                    // Email notification
                    $donorName = $contribution->is_anonymous ? 'Anonymous' : $contribution->donor_name;
                    \App\Jobs\SendContributionEmail::dispatch(
                        $owner,
                        $donorName,
                        (float) $creditAmount,
                        $gift->name,
                        $gift->registry->name,
                        config('app.frontend_url') . '/dashboard/registry/' . $gift->registry_id . '/gift/' . $gift->id,
                    );
                }
            }

            return true;
        });
    }

    public function getContributors(Gift $gift): \Illuminate\Database\Eloquent\Collection
    {
        return $gift->successfulContributions()
            ->select([
                'id',
                'donor_name',
                'donor_email',
                'amount',
                'is_anonymous',
                'created_at',
                DB::raw("CASE WHEN is_anonymous = true THEN 'Anonymous' ELSE donor_name END as display_name"),
            ])
            ->latest()
            ->get();
    }
}
