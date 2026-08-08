<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class UserOtp extends Model
{
    protected $fillable = [
        'user_id',
        'otp',
        'type',
        'attempts',
        'is_used',
        'locked_until',
        'expires_at',
        'used_at',
    ];

    protected $casts = [
        'is_used'      => 'boolean',
        'locked_until' => 'datetime',
        'expires_at'   => 'datetime',
        'used_at'      => 'datetime',
    ];

    /* ── Relationships ── */

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /* ── Helpers ── */

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isLocked(): bool
    {
        return $this->locked_until && $this->locked_until->isFuture();
    }

    public function isValid(string $otp): bool
    {
        return !$this->is_used
            && !$this->isExpired()
            && !$this->isLocked()
            && $this->otp === $otp;
    }
}
