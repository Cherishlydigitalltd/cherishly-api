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
    ];

    protected $casts = [
        'price'                  => 'decimal:2',
        'amount_contributed'     => 'decimal:2',
        'quantity'               => 'integer',
        'allow_cash_contribution'=> 'boolean',
    ];

    protected $appends = ['funded_percentage', 'is_fully_funded'];

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

    /* ── Relationships ── */

    public function registry()
    {
        return $this->belongsTo(GiftRegistry::class, 'registry_id');
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
