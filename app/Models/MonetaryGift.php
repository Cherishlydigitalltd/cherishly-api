<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

class MonetaryGift extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'title',
        'description',
        'target_amount',
        'amount_raised',
        'cover_photo',
        'share_token',
        'is_active',
    ];

    protected $casts = [
        'target_amount' => 'decimal:2',
        'amount_raised' => 'decimal:2',
        'is_active'     => 'boolean',
    ];

    protected $appends = ['funded_percentage', 'public_url'];

    /* ── Boot ── */

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($gift) {
            $gift->share_token = Str::random(16);
        });
    }

    /* ── Accessors ── */

    public function getFundedPercentageAttribute(): float
    {
        if ($this->target_amount <= 0) return 0;
        return min(100, round(($this->amount_raised / $this->target_amount) * 100, 2));
    }

    public function getPublicUrlAttribute(): string
    {
        return config('app.frontend_url') . '/give/' . $this->share_token;
    }

    /* ── Relationships ── */

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function contributions()
    {
        return $this->hasMany(MonetaryContribution::class);
    }

    public function successfulContributions()
    {
        return $this->hasMany(MonetaryContribution::class)
            ->where('payment_status', 'successful');
    }
}
