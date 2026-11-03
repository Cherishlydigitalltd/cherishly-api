<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Gift extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'registry_id',
        'name',
        'description',
        'price',
        'quantity',
        'category',
        'image',
        'type',
        'allow_cash_contribution',
        'amount_contributed',
        'vendor_product_id',
        'vendor_id',
        'fulfilment',
    ];

    protected $casts = [
        'price'                  => 'decimal:2',
        'amount_contributed'     => 'decimal:2',
        'quantity'               => 'integer',
        'allow_cash_contribution'=> 'boolean',
    ];

    protected $appends = ['funded_percentage', 'is_fully_funded', 'is_vendor_item', 'amount_remaining'];

    /* ── Accessors ── */

    public function getFundedPercentageAttribute(): float
    {
        if ($this->price <= 0) return 0;
        return min(100, round(($this->amount_contributed / $this->price) * 100, 2));
    }

    public function getIsFullyFundedAttribute(): bool
    {
        return $this->amount_contributed >= $this->price;
    }

    public function getIsVendorItemAttribute(): bool
    {
        return $this->vendor_product_id !== null || $this->vendor_id !== null;
    }

    public function getAmountRemainingAttribute(): float
    {
        return max(0, round((float) $this->price - (float) $this->amount_contributed, 2));
    }

    /** Guests can still pay toward it: not fully paid, not ordered, not cashed out. */
    public function isCollecting(): bool
    {
        return $this->fulfilment === null && !$this->is_fully_funded;
    }

    /* ── Relationships ── */

    public function registry()
    {
        return $this->belongsTo(GiftRegistry::class, 'registry_id');
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function vendorOrder()
    {
        return $this->hasOne(VendorOrder::class)->latestOfMany();
    }

    public function contributions()
    {
        return $this->hasMany(Contribution::class);
    }

    public function successfulContributions()
    {
        return $this->hasMany(Contribution::class)->where('payment_status', 'successful');
    }
}
