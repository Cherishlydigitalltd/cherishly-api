<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class EventGuest extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_id', 'full_name', 'email', 'phone',
        'allow_plus_one', 'rsvp_status', 'qr_token', 'rsvp_token', 'checked_in_at',
    ];

    protected $casts = [
        'allow_plus_one' => 'boolean',
        'checked_in_at'  => 'datetime',
    ];

    public function event() { return $this->belongsTo(Event::class); }

    protected static function booted(): void
    {
        // Private code for the guest's RSVP link (never their numeric id).
        static::creating(function (EventGuest $guest) {
            $guest->rsvp_token ??= \Illuminate\Support\Str::random(32);
        });
    }

    public function getQrUrlAttribute(): string
    {
        return 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data='
            . urlencode(config('app.frontend_url') . '/checkin/' . $this->qr_token);
    }
}
