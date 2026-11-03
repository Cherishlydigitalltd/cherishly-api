<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VendorOrder extends Model
{
    // Orders the vendor still has to act on.
    public const OPEN = ['placed', 'accepted', 'dispatched'];

    protected $fillable = [
        'reference', 'vendor_id', 'gift_id', 'registry_id', 'user_id', 'vendor_product_id',
        'item_name', 'item_image', 'amount', 'commission_rate', 'commission_amount',
        'vendor_amount', 'status', 'delivery_name', 'delivery_phone', 'delivery_address',
        'delivery_state', 'vendor_note', 'cancel_reason', 'cancelled_by', 'dispute_reason', 'placed_at',
        'accepted_at', 'dispatched_at', 'delivered_at', 'auto_complete_at', 'completed_at',
        'disputed_at', 'cancelled_at', 'payout_status', 'payout_reference', 'paid_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'commission_rate' => 'decimal:2',
        'commission_amount' => 'decimal:2',
        'vendor_amount' => 'decimal:2',
        'placed_at' => 'datetime',
        'accepted_at' => 'datetime',
        'dispatched_at' => 'datetime',
        'delivered_at' => 'datetime',
        'auto_complete_at' => 'datetime',
        'completed_at' => 'datetime',
        'disputed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function gift()
    {
        return $this->belongsTo(Gift::class)->withTrashed();
    }

    public function registry()
    {
        return $this->belongsTo(GiftRegistry::class, 'registry_id')->withTrashed();
    }

    public function celebrant()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
