<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'phone',
        'password',
        'avatar',
        'is_email_verified',
        'is_phone_verified',
        'is_active',
        'email_verified_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'is_email_verified' => 'boolean',
        'is_phone_verified' => 'boolean',
        'is_active' => 'boolean',
        'password' => 'hashed',
    ];

    protected $appends = ['full_name'];

    /* ── Accessors ── */

    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    /* ── Relationships ── */

    public function otps()
    {
        return $this->hasMany(UserOtp::class);
    }

    public function wallet()
    {
        return $this->hasOne(Wallet::class);
    }

    public function registries()
    {
        return $this->hasMany(GiftRegistry::class);
    }

    public function monetaryGifts()
    {
        return $this->hasMany(MonetaryGift::class);
    }

    public function invitations()
    {
        return $this->hasMany(Invitation::class);
    }

    public function secretSantas()
    {
        return $this->hasMany(SecretSanta::class);
    }

    public function memoryWalls()
    {
        return $this->hasMany(MemoryWall::class);
    }

    public function events()
    {
        return $this->hasMany(Event::class);
    }
}
