<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class VendorProduct extends Model
{
    use SoftDeletes;

    public const MAX_IMAGES = 5;

    // Edits to these wait for admin approval on an item that is already live.
    public const REVIEWED_FIELDS = ['name', 'description', 'category', 'price', 'images'];

    protected $fillable = [
        'vendor_id', 'name', 'description', 'category', 'price', 'images', 'stock',
        'status', 'pending_changes', 'status_reason', 'is_active',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'images' => 'array',
        'pending_changes' => 'array',
        'stock' => 'integer',
        'is_active' => 'boolean',
    ];

    protected $appends = ['image'];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function getImageAttribute(): ?string
    {
        return $this->images[0] ?? null;
    }

    public function inStock(): bool
    {
        return $this->stock === null || $this->stock > 0;
    }

    /** Items celebrants can see and add: approved, switched on, in stock, from an approved vendor. */
    public function scopeListed(Builder $query): Builder
    {
        return $query->where('status', 'approved')
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('stock')->orWhere('stock', '>', 0))
            ->whereHas('vendor', fn ($q) => $q->where('status', 'approved'));
    }

    public function isListed(): bool
    {
        return $this->status === 'approved' && $this->is_active && $this->inStock()
            && $this->vendor?->isApproved();
    }
}
