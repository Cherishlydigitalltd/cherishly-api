<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

class Invitation extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'title',
        'description',
        'cover_photo',
        'share_token',
        'rsvp_deadline',
        'scheduled_at',
        'sent_at',
    ];

    protected $casts = [
        'rsvp_deadline' => 'datetime',
        'scheduled_at'  => 'datetime',
        'sent_at'       => 'datetime',
    ];

    protected $appends = ['public_url', 'stats', 'is_sent'];

    /* ── Boot ── */

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($invitation) {
            $invitation->share_token = Str::random(16);
        });
    }

    /* ── Accessors ── */

    public function getPublicUrlAttribute(): string
    {
        return config('app.frontend_url') . '/rsvp/' . $this->share_token;
    }

    /** Read by the web dashboard's Sent/Draft badge. */
    public function getIsSentAttribute(): bool
    {
        return $this->sent_at !== null;
    }

    public function getStatsAttribute(): array
    {
        return [
            'total'         => $this->guests()->count(),
            'attending'     => $this->guests()->where('rsvp_status', 'attending')->count(),
            'not_attending' => $this->guests()->where('rsvp_status', 'not_attending')->count(),
            'pending'       => $this->guests()->where('rsvp_status', 'pending')->count(),
            'checked_in'    => $this->guests()->where('checked_in', true)->count(),
        ];
    }

    public function isRsvpOpen(): bool
    {
        return !$this->rsvp_deadline || $this->rsvp_deadline->isFuture();
    }

    /* ── Relationships ── */

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function guests()
    {
        return $this->hasMany(InvitationGuest::class);
    }
}
