<?php

namespace App\Services;

use App\Jobs\SendMarketplaceEmail;
use App\Models\Contribution;
use App\Models\Gift;
use App\Models\User;
use App\Models\VendorOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Money and orders for vendor items.
 *
 * Payments toward a vendor item are held (not put in the celebrant's wallet).
 * Once the item is fully paid it becomes an order for the vendor. The vendor is
 * paid, minus commission, after the celebrant confirms delivery, or
 * AUTO_COMPLETE_DAYS after the vendor marks it delivered. If the order can't go
 * ahead, or the celebrant takes the cash instead, the money goes to the
 * celebrant's wallet like any other contribution.
 */
class MarketplaceService
{
    public const AUTO_COMPLETE_DAYS = 3;

    public function __construct(
        private GatewayService $gateway,
        private NotificationService $notifications,
    ) {
    }

    public function commissionRate(): float
    {
        return (float) setting('vendor_commission_rate', 10);
    }

    /* ────────────────────────────────────────────
     | PAYMENTS IN
     ──────────────────────────────────────────── */

    /**
     * A guest's payment toward a vendor item went through. Called from the
     * payment webhook, inside its transaction.
     */
    public function recordPayment(Gift $gift, Contribution $contribution): void
    {
        $gift = Gift::whereKey($gift->id)->lockForUpdate()->first();
        $owner = $gift->registry->user;
        $amount = (float) ($contribution->net_amount ?? $contribution->amount);
        $donor = $contribution->is_anonymous ? 'Someone' : $contribution->donor_name;

        // Already ordered or cashed out (two guests paid at the same time):
        // the money is still a gift to the celebrant, as cash.
        if ($gift->fulfilment !== null) {
            $this->creditCelebrant($owner, $amount, "Contribution for {$gift->name} (paid after it was settled)", $contribution->payment_reference);
            $this->notifications->create($owner, 'contribution', 'New contribution received!',
                "{$donor} contributed ₦" . number_format($amount, 2) . " toward {$gift->name}. It was already settled, so it has gone to your wallet.",
                $this->giftLink($gift), '🎁');
            return;
        }

        $remaining = max(0, (float) $gift->price - (float) $gift->amount_contributed);
        $towardItem = min($amount, $remaining);
        $extra = round($amount - $towardItem, 2);

        $gift->increment('amount_contributed', $towardItem);
        if ($extra > 0) {
            $this->creditCelebrant($owner, $extra, "Extra paid toward {$gift->name}", $contribution->payment_reference);
        }

        $this->notifications->create($owner, 'contribution', 'New contribution received!',
            "{$donor} contributed ₦" . number_format($amount, 2) . " toward {$gift->name}.",
            $this->giftLink($gift), '🎁');

        if ($gift->fresh()->is_fully_funded) {
            $this->placeOrder($gift->fresh());
        }
    }

    /** The item is fully paid: send it to the vendor. */
    public function placeOrder(Gift $gift): VendorOrder
    {
        $gift->loadMissing('registry.user', 'vendor.user');
        $registry = $gift->registry;
        $vendor = $gift->vendor;
        $rate = $this->commissionRate();
        $amount = (float) $gift->price;
        $commission = round($amount * $rate / 100, 2);

        $order = VendorOrder::create([
            'reference' => 'CHO-' . strtoupper(Str::random(8)),
            'vendor_id' => $vendor->id,
            'gift_id' => $gift->id,
            'registry_id' => $registry->id,
            'user_id' => $registry->user_id,
            'vendor_product_id' => $gift->vendor_product_id,
            'item_name' => $gift->name,
            'item_image' => $gift->image,
            'amount' => $amount,
            'commission_rate' => $rate,
            'commission_amount' => $commission,
            'vendor_amount' => round($amount - $commission, 2),
            'status' => 'awaiting_address',
        ]);

        $gift->update(['fulfilment' => 'order']);

        if ($gift->vendor_product_id) {
            DB::table('vendor_products')->where('id', $gift->vendor_product_id)
                ->whereNotNull('stock')->where('stock', '>', 0)->decrement('stock');
        }

        // Use the registry's saved delivery address when the vendor covers it.
        if ($registry->delivery_address && $vendor->deliversTo($registry->delivery_state)) {
            return $this->place($order, [
                'delivery_name' => $registry->delivery_name,
                'delivery_phone' => $registry->delivery_phone,
                'delivery_address' => $registry->delivery_address,
                'delivery_state' => $registry->delivery_state,
            ]);
        }

        $owner = $registry->user;
        $this->notifications->create($owner, 'order', 'Fully paid: add a delivery address',
            "{$gift->name} has been fully paid for by your guests. Tell us where to deliver it.",
            $this->giftLink($gift), '🎉');
        $this->email($owner->email, $owner->first_name, "{$gift->name} is fully paid for!", [
            "Great news, {$owner->first_name}! Your guests have fully paid for {$gift->name} from {$vendor->business_name}.",
            'Add a delivery address so the vendor can send it to you. If you would rather have the money, you can take it as cash instead.',
        ], 'ADD DELIVERY ADDRESS', $this->giftLink($gift, true));

        return $order;
    }

    /** Delivery details are in: the vendor can start. */
    public function place(VendorOrder $order, array $delivery): VendorOrder
    {
        $order->update($delivery + ['status' => 'placed', 'placed_at' => now()]);

        $vendorUser = $order->vendor->user;
        $this->notifications->create($vendorUser, 'vendor_order', 'New order',
            "New order {$order->reference}: {$order->item_name}, to {$order->delivery_state}. Please accept it.",
            "/vendor/orders/{$order->id}", '📦');
        $this->email($order->vendor->email, $order->vendor->contact_name, "New order: {$order->item_name}", [
            "You have a new Cherishly order ({$order->reference}) for {$order->item_name}.",
            'It is fully paid. You will receive ₦' . number_format($order->vendor_amount, 2)
                . ' once the celebrant confirms delivery.',
            'Please accept it and deliver to ' . $order->delivery_state . '.',
        ], 'VIEW ORDER', config('app.frontend_url') . "/vendor/orders/{$order->id}");

        return $order->fresh();
    }

    /* ────────────────────────────────────────────
     | CELEBRANT
     ──────────────────────────────────────────── */

    /** Delivery address for an order waiting on one. Also saved on the registry. */
    public function setAddress(VendorOrder $order, array $data): VendorOrder
    {
        return DB::transaction(function () use ($order, $data) {
            $order = VendorOrder::whereKey($order->id)->lockForUpdate()->first();
            if (!in_array($order->status, ['awaiting_address', 'placed'], true)) {
                throw new \RuntimeException('The vendor has already started on this order, so the address can no longer be changed. Please contact support.');
            }
            if (!$order->vendor->deliversTo($data['delivery_state'])) {
                throw new \RuntimeException("{$order->vendor->business_name} doesn’t deliver to {$data['delivery_state']}. Choose another state, or take the cash instead.");
            }

            $order->registry->update($data);

            if ($order->status === 'placed') {
                $order->update($data);
                return $order->fresh();
            }
            return $this->place($order, $data);
        });
    }

    /**
     * Take the money collected for a vendor item as cash instead. Allowed until
     * the vendor accepts the order.
     */
    public function takeCash(Gift $gift): Gift
    {
        return DB::transaction(function () use ($gift) {
            $gift = Gift::whereKey($gift->id)->lockForUpdate()->first();
            if (!$gift->is_vendor_item || $gift->fulfilment === 'cash') {
                throw new \RuntimeException('This gift can’t be taken as cash.');
            }

            if ($gift->fulfilment === 'order') {
                $order = $gift->vendorOrder;
                if (!$order || !in_array($order->status, ['awaiting_address', 'placed'], true)) {
                    throw new \RuntimeException('The vendor has already accepted this order, so it can’t be swapped for cash.');
                }
                $this->cancel($order, 'The celebrant chose to take the cash instead.', 'celebrant');
                return $gift->fresh();
            }

            if ((float) $gift->amount_contributed <= 0) {
                throw new \RuntimeException('Nothing has been paid toward this gift yet.');
            }

            $gift->update(['fulfilment' => 'cash']);
            $this->creditCelebrant($gift->registry->user, (float) $gift->amount_contributed,
                "Cash taken for {$gift->name}", 'CASH-GIFT-' . $gift->id);

            return $gift->fresh();
        });
    }

    public function confirmReceived(VendorOrder $order): VendorOrder
    {
        $order = $this->transition($order, ['dispatched', 'delivered'], fn ($o) => $o->update([
            'status' => 'completed',
            'completed_at' => now(),
            'delivered_at' => $o->delivered_at ?? now(),
        ]));
        $this->notifyVendor($order, 'Delivery confirmed',
            "The celebrant confirmed they received {$order->item_name} ({$order->reference}). Your payout is on its way.", '✅');
        $this->payVendor($order);
        return $order->fresh();
    }

    public function reportProblem(VendorOrder $order, string $reason): VendorOrder
    {
        $order = $this->transition($order, ['accepted', 'dispatched', 'delivered'], fn ($o) => $o->update([
            'status' => 'disputed',
            'dispute_reason' => $reason,
            'disputed_at' => now(),
            'auto_complete_at' => null,
        ]));
        $this->notifyVendor($order, 'Problem reported',
            "The celebrant reported a problem with {$order->item_name} ({$order->reference}). Cherishly support will be in touch; your payout is on hold.", '⚠️');
        return $order;
    }

    /* ────────────────────────────────────────────
     | VENDOR
     ──────────────────────────────────────────── */

    public function accept(VendorOrder $order): VendorOrder
    {
        $order = $this->transition($order, ['placed'], fn ($o) => $o->update([
            'status' => 'accepted',
            'accepted_at' => now(),
        ]));
        $this->notifyCelebrant($order, 'Order accepted',
            "{$order->vendor->business_name} accepted your order for {$order->item_name} and is preparing it.", '📦');
        return $order;
    }

    public function decline(VendorOrder $order, string $reason): VendorOrder
    {
        return DB::transaction(function () use ($order, $reason) {
            $order = VendorOrder::whereKey($order->id)->lockForUpdate()->first();
            if (!in_array($order->status, ['placed', 'accepted'], true)) {
                throw new \RuntimeException('This order can no longer be declined.');
            }
            return $this->cancel($order, $reason, 'vendor');
        });
    }

    public function dispatch(VendorOrder $order, ?string $note): VendorOrder
    {
        $order = $this->transition($order, ['accepted'], fn ($o) => $o->update([
            'status' => 'dispatched',
            'dispatched_at' => now(),
            'vendor_note' => $note ?: $o->vendor_note,
        ]));
        $this->notifyCelebrant($order, 'On its way!',
            "{$order->item_name} is on its way to you." . ($note ? " Note from the vendor: {$note}" : ''), '🚚', true);
        return $order;
    }

    public function markDelivered(VendorOrder $order): VendorOrder
    {
        $order = $this->transition($order, ['accepted', 'dispatched'], fn ($o) => $o->update([
            'status' => 'delivered',
            'delivered_at' => now(),
            'dispatched_at' => $o->dispatched_at ?? now(),
            'auto_complete_at' => now()->addDays(self::AUTO_COMPLETE_DAYS),
        ]));
        $this->notifyCelebrant($order, 'Delivered: please confirm',
            "{$order->vendor->business_name} says {$order->item_name} has been delivered. Please confirm you received it, or report a problem within "
                . self::AUTO_COMPLETE_DAYS . ' days.', '🎁', true);
        return $order;
    }

    /* ────────────────────────────────────────────
     | ADMIN
     ──────────────────────────────────────────── */

    /** Settle a disputed order: pay the vendor, or refund the celebrant. */
    public function resolve(VendorOrder $order, string $outcome, ?string $note = null): VendorOrder
    {
        if ($outcome === 'celebrant') {
            return DB::transaction(function () use ($order, $note) {
                $order = VendorOrder::whereKey($order->id)->lockForUpdate()->first();
                if ($order->status !== 'disputed') {
                    throw new \RuntimeException('Only disputed orders can be resolved.');
                }
                return $this->cancel($order, $note ?: 'Refunded after a reported problem.', 'admin');
            });
        }

        $order = $this->transition($order, ['disputed'], fn ($o) => $o->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]));
        $this->notifyVendor($order, 'Problem resolved',
            "The problem with {$order->reference} was resolved in your favour. Your payout is on its way.", '✅');
        $this->payVendor($order);
        return $order->fresh();
    }

    /* ────────────────────────────────────────────
     | PAYOUTS
     ──────────────────────────────────────────── */

    /** Send the vendor their money for a completed order. Safe to call again after a failure. */
    public function payVendor(VendorOrder $order): VendorOrder
    {
        $id = $order->id;
        $order = DB::transaction(function () use ($id) {
            $order = VendorOrder::whereKey($id)->lockForUpdate()->first();
            if ($order->status !== 'completed' || !in_array($order->payout_status, ['none', 'failed'], true)) {
                return null;
            }
            $order->update([
                'payout_status' => 'processing',
                'payout_reference' => 'VPO-' . strtoupper(Str::random(12)),
            ]);
            return $order;
        });
        if (!$order) {
            return VendorOrder::find($id);
        }

        $vendor = $order->vendor;
        if (!$vendor->hasBank()) {
            $order->update(['payout_status' => 'failed']);
            $this->notifyVendor($order, 'Add your bank account',
                "We couldn’t pay you for {$order->reference} because your bank account isn’t set up. Add it in Settings and we’ll pay you.", '🏦');
            return $order->fresh();
        }

        $result = $this->gateway->initiateWithdrawal([
            'reference' => $order->payout_reference,
            'amount' => (float) $order->vendor_amount,
            'account_number' => $vendor->account_number,
            'account_name' => $vendor->account_name,
            'bank_code' => $vendor->bank_code,
            'bank_name' => $vendor->bank_name,
            'narration' => "Cherishly order {$order->reference}",
        ]);

        if (!$result['success']) {
            $order->update(['payout_status' => 'failed']);
            Log::error('Vendor payout failed to start', ['order' => $order->reference, 'error' => $result['message'] ?? null]);
        }

        return $order->fresh();
    }

    /** Payout webhook. Returns false if the reference isn't a vendor payout. */
    public function confirmPayout(string $reference, string $status): bool
    {
        return DB::transaction(function () use ($reference, $status) {
            $order = VendorOrder::where('payout_reference', $reference)->lockForUpdate()->first();
            if (!$order) {
                return false;
            }
            if ($order->payout_status !== 'processing') {
                return true; // repeat webhook
            }

            if ($status === 'successful') {
                $order->update(['payout_status' => 'paid', 'paid_at' => now()]);
                $this->notifyVendor($order, 'Payment sent',
                    '₦' . number_format($order->vendor_amount, 2) . " for {$order->reference} has been sent to your bank.", '💸');
            } else {
                $order->update(['payout_status' => 'failed']);
                Log::error('Vendor payout failed', ['order' => $order->reference]);
            }
            return true;
        });
    }

    /** Scheduled: complete delivered orders the celebrant didn't respond to. */
    public function autoComplete(): int
    {
        $due = VendorOrder::where('status', 'delivered')
            ->where('auto_complete_at', '<=', now())
            ->get();

        foreach ($due as $order) {
            $order = $this->transition($order, ['delivered'], fn ($o) => $o->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]));
            $this->payVendor($order);
        }
        return $due->count();
    }

    /* ────────────────────────────────────────────
     | HELPERS
     ──────────────────────────────────────────── */

    /** Cancel an order: the money goes to the celebrant as cash. Caller holds the lock. */
    private function cancel(VendorOrder $order, string $reason, string $by): VendorOrder
    {
        $order->update([
            'status' => 'cancelled',
            'cancel_reason' => $reason,
            'cancelled_by' => $by,
            'cancelled_at' => now(),
            'auto_complete_at' => null,
        ]);
        $order->gift?->update(['fulfilment' => 'cash']);

        if ($order->vendor_product_id) {
            DB::table('vendor_products')->where('id', $order->vendor_product_id)
                ->whereNotNull('stock')->increment('stock');
        }

        $this->creditCelebrant($order->celebrant, (float) $order->amount,
            "Refund for {$order->item_name} ({$order->reference})", 'CASH-' . $order->reference);

        if ($by !== 'celebrant') {
            $this->notifyCelebrant($order, 'Order cancelled',
                "Your order for {$order->item_name} was cancelled ({$reason}). ₦" . number_format($order->amount, 2)
                    . ' has been added to your wallet.', '↩️', true);
        }
        if ($by !== 'vendor' && $order->placed_at) {
            $this->notifyVendor($order, 'Order cancelled', "Order {$order->reference} for {$order->item_name} was cancelled.", '↩️');
        }
        return $order->fresh();
    }

    /** Lock the order, check it's in an allowed state, apply the change. */
    private function transition(VendorOrder $order, array $from, callable $change): VendorOrder
    {
        return DB::transaction(function () use ($order, $from, $change) {
            $order = VendorOrder::whereKey($order->id)->lockForUpdate()->first();
            if (!in_array($order->status, $from, true)) {
                throw new \RuntimeException('This order has moved on. Please refresh the page.');
            }
            $change($order);
            return $order->fresh();
        });
    }

    /**
     * Put money in the celebrant's wallet, the same way gift contributions are
     * credited (available to withdraw the next day).
     */
    public function creditCelebrant(User $owner, float $amount, string $description, string $reference): void
    {
        if ($amount <= 0) {
            return;
        }
        $wallet = app(WalletService::class)->getWallet($owner);
        $wallet->increment('balance', $amount);
        $wallet->increment('total_received', $amount);
        $wallet->increment('pending_settlement', $amount);
        $wallet->transactions()->create([
            'user_id' => $owner->id,
            'type' => 'credit',
            'amount' => $amount,
            'description' => $description,
            'reference' => $reference,
            'status' => 'successful',
            'available_at' => now()->addDay(),
        ]);
    }

    private function notifyCelebrant(VendorOrder $order, string $title, string $body, string $icon, bool $email = false): void
    {
        $owner = $order->celebrant;
        $link = "/dashboard/registry/{$order->registry_id}/gift/{$order->gift_id}";
        $this->notifications->create($owner, 'order', $title, $body, $link, $icon);
        if ($email) {
            $this->email($owner->email, $owner->first_name, $title, [$body], 'VIEW GIFT', config('app.frontend_url') . $link);
        }
    }

    private function notifyVendor(VendorOrder $order, string $title, string $body, string $icon): void
    {
        $this->notifications->create($order->vendor->user, 'vendor_order', $title, $body, "/vendor/orders/{$order->id}", $icon);
    }

    private function giftLink(Gift $gift, bool $full = false): string
    {
        $path = "/dashboard/registry/{$gift->registry_id}/gift/{$gift->id}";
        return $full ? config('app.frontend_url') . $path : $path;
    }

    private function email(string $to, ?string $name, string $subject, array $lines, ?string $button = null, ?string $url = null): void
    {
        SendMarketplaceEmail::dispatch($to, $name ?: 'there', $subject, $lines, $button, $url);
    }
}
