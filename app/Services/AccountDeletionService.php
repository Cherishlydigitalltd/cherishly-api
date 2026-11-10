<?php

namespace App\Services;

use App\Models\Gift;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VendorOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Deletes a customer's account (Google Play / privacy-policy requirement).
 *
 * Refuses while money is still in motion, so nobody loses funds or leaves a
 * vendor order stranded. Otherwise it removes the person's details and
 * content and keeps payment records (anonymised) for the legal retention
 * period described at cherishlyng.com/delete-account.
 */
class AccountDeletionService
{
    public function __construct(private AssetService $assets)
    {
    }

    /**
     * Reasons the account can't be deleted yet; empty when it can.
     *
     * @return string[]
     */
    public function blockers(User $user): array
    {
        $reasons = [];

        $wallet = $user->wallet;
        if ($wallet && (float) $wallet->balance > 0) {
            $settling = (float) $wallet->balance - (float) $wallet->available_balance;
            $reasons[] = $settling > 0
                ? 'You have ₦' . number_format((float) $wallet->balance, 2) . ' in your wallet, of which ₦'
                    . number_format($settling, 2) . ' is still settling. Withdraw it once it’s available, then try again.'
                : 'You have ₦' . number_format((float) $wallet->balance, 2) . ' in your wallet. Withdraw it first.';
        }

        if ($wallet && Transaction::where('wallet_id', $wallet->id)->where('type', 'debit')->where('status', 'pending')->exists()) {
            $reasons[] = 'A withdrawal is still being processed. Try again once it has gone through.';
        }

        // Vendor gifts on your registries: open orders, or money held for an
        // item that isn't fully paid yet.
        $registryIds = $user->registries()->withTrashed()->pluck('id');
        if (VendorOrder::whereIn('registry_id', $registryIds)
            ->whereIn('status', ['awaiting_address', 'placed', 'accepted', 'dispatched', 'delivered', 'disputed'])
            ->exists()) {
            $reasons[] = 'You have a vendor gift order that isn’t finished. Confirm it arrived, or take the cash, first.';
        }
        if (Gift::whereIn('registry_id', $registryIds)
            ->whereNotNull('vendor_product_id')
            ->whereNull('fulfilment')
            ->where('amount_contributed', '>', 0)
            ->exists()) {
            $reasons[] = 'Guests have paid towards a vendor gift that isn’t fully paid yet. Take that money as cash first.';
        }

        // Vendors: orders still to deliver, or payouts not yet received.
        if ($vendor = $user->vendor) {
            if ($vendor->orders()->whereIn('status', ['placed', 'accepted', 'dispatched', 'delivered', 'disputed'])->exists()) {
                $reasons[] = 'Your shop has orders that aren’t finished. Complete them first.';
            }
            if ($vendor->orders()->where('status', 'completed')->whereIn('payout_status', ['none', 'processing', 'failed'])->exists()) {
                $reasons[] = 'Your shop is still owed a payout. Wait until it has been paid, or contact support.';
            }
        }

        return $reasons;
    }

    /**
     * Delete the account. Call blockers() first; this refuses if any remain.
     */
    public function delete(User $user, string $by = 'user'): void
    {
        if ($reasons = $this->blockers($user)) {
            throw new \RuntimeException($reasons[0]);
        }

        $avatar = $user->avatar;
        $vendorLogo = $user->vendor?->logo;

        DB::transaction(function () use ($user) {
            // Guest lists and messages: personal data of other people that only
            // exists for this account's events. No financial value, so removed.
            foreach ($user->invitations()->withTrashed()->get() as $invitation) {
                $invitation->guests()->delete();
            }
            foreach ($user->events()->withTrashed()->get() as $event) {
                $event->guests()->delete();
            }
            foreach ($user->secretSantas()->withTrashed()->get() as $santa) {
                $santa->participants()->delete();
            }
            foreach ($user->memoryWalls()->withTrashed()->get() as $wall) {
                $wall->wishes()->delete();
            }

            // Content: soft-deleted so public links stop working, while gifts and
            // contributions stay attached to the payment records we must keep.
            foreach (['registries', 'monetaryGifts', 'invitations', 'secretSantas', 'memoryWalls', 'events'] as $relation) {
                $user->{$relation}()->get()->each->delete();
            }

            if ($vendor = $user->vendor) {
                $vendor->products()->get()->each->delete();
                $vendor->update([
                    'status' => 'suspended',
                    'status_reason' => 'Account deleted',
                    'business_name' => 'Deleted vendor',
                    'contact_name' => 'Deleted',
                    'phone' => '-',
                    'email' => 'deleted-vendor-' . $vendor->id . '@deleted.cherishlyng.com',
                    'address' => '-',
                    'description' => null,
                    'logo' => null,
                    'cac_number' => null,
                    'bank_code' => null,
                    'bank_name' => null,
                    'account_number' => null,
                    'account_name' => null,
                ]);
            }

            if ($wallet = $user->wallet) {
                $wallet->forceFill([
                    'bank_name' => null,
                    'bank_code' => null,
                    'account_number' => null,
                    'account_name' => null,
                    'bvn_encrypted' => null,
                    'bvn_verified' => false,
                ])->save();
            }

            $user->notifications()->delete();
            $user->otps()->delete();
            $user->tokens()->delete();

            // Anonymise, then soft-delete: transactions and contributions keep
            // pointing at this row, but nothing identifies the person any more.
            // The email is freed so they can sign up again later.
            $user->forceFill([
                'first_name' => 'Deleted',
                'last_name' => 'user',
                'email' => 'deleted-' . $user->id . '-' . Str::lower(Str::random(8)) . '@deleted.cherishlyng.com',
                'phone' => null,
                'avatar' => null,
                'password' => Hash::make(Str::random(40)),
                'is_active' => false,
                'remember_token' => null,
            ])->save();
            $user->delete();
        });

        // Files live on the asset server; remove them after the data is gone.
        foreach (array_filter([$avatar, $vendorLogo]) as $url) {
            $this->assets->delete($url);
        }

        Log::info('Account deleted', ['user_id' => $user->id, 'by' => $by]);
    }
}
