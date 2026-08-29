<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

class InvitationGuest extends Model
{
    use HasFactory;

    protected $fillable = [
        'invitation_id',
        'full_name',
        'email',
        'phone',
        'rsvp_status',
        'allow_plus_one',
        'has_plus_one',
        'checked_in',
        'checked_in_at',
        'rsvp_responded_at',
        'qr_token',
    ];

    protected $casts = [
        'allow_plus_one'     => 'boolean',
        'has_plus_one'       => 'boolean',
        'checked_in'         => 'boolean',
        'checked_in_at'      => 'datetime',
        'rsvp_responded_at'  => 'datetime',
    ];

    /* ── Boot ── */

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($guest) {
            $guest->qr_token = Str::random(32);
        });
    }

    /* ── Relationships ── */

    public function invitation()
    {
        return $this->belongsTo(Invitation::class);
    }

    /* ── Scopes ── */

    public function scopeAttending($query)
    {
        return $query->where('rsvp_status', 'attending');
    }

    public function scopePending($query)
    {
        return $query->where('rsvp_status', 'pending');
    }

    public function scopeCheckedIn($query)
    {
        return $query->where('checked_in', true);
    }
}
