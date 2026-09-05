<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

class GiftRegistry extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'name',
        'description',
        'cover_photo',
        'is_public',
        'share_token',
    ];

    protected $casts = [
        'is_public' => 'boolean',
    ];

    protected $appends = ['public_url', 'total_gifts', 'total_contributed'];

    /* ── Boot ── */

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($registry) {
            $registry->share_token = Str::random(16);
        });
    }

    /* ── Accessors ── */

    public function getPublicUrlAttribute(): string
    {
        return config('app.frontend_url') . '/registry/' . $this->share_token;
    }

    public function getTotalGiftsAttribute(): int
    {
        return $this->gifts()->count();
    }

    public function getTotalContributedAttribute(): float
    {
        return $this->gifts()->sum('amount_contributed');
    }

    /* ── Relationships ── */

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function gifts()
    {
        return $this->hasMany(Gift::class, 'registry_id');
    }
}
