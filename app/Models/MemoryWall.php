<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

class MemoryWall extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'title',
        'description',
        'cover_photo',
        'share_token',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $appends = ['public_url', 'wishes_count'];

    /* ── Boot ── */

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($wall) {
            $wall->share_token = Str::random(16);
        });
    }

    /* ── Accessors ── */

    public function getPublicUrlAttribute(): string
    {
        return config('app.frontend_url') . '/wall/' . $this->share_token;
    }

    public function getWishesCountAttribute(): int
    {
        return $this->wishes()->count();
    }

    /* ── Relationships ── */

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function wishes()
    {
        return $this->hasMany(Wish::class, 'wall_id');
    }
}
