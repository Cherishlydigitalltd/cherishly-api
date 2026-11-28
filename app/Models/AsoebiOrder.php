<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AsoebiOrder extends Model
{
    protected $fillable = [
        'event_id', 'asoebi_package_id', 'order_no', 'buyer_name', 'email', 'phone', 'quantity', 'unit_price',
        'delivery', 'delivery_address', 'delivery_fee', 'amount', 'gross_amount', 'net_amount', 'fee_amount',
        'fee_rate', 'fee_bearer', 'payment_reference', 'payment_status', 'payment_meta', 'paid_at',
        'pickup_token', 'handed_over_at', 'handed_over_by', 'reminded_at',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'delivery' => 'boolean',
        'delivery_fee' => 'decimal:2',
        'amount' => 'decimal:2',
        'gross_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'fee_amount' => 'decimal:2',
        'fee_rate' => 'decimal:2',
        'payment_meta' => 'array',
        'paid_at' => 'datetime',
        'handed_over_at' => 'datetime',
        'reminded_at' => 'datetime',
    ];

    protected $hidden = ['payment_meta'];

    protected static function booted(): void
    {
        static::creating(function (AsoebiOrder $o) {
            $o->pickup_token ??= Str::random(32);
        });
    }

    public function event() { return $this->belongsTo(Event::class); }
    public function package() { return $this->belongsTo(AsoebiPackage::class, 'asoebi_package_id'); }
}
