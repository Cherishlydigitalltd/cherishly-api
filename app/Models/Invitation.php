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
    ];

    protected $casts = [
        'rsvp_deadline' => 'datetime',
    ];

    protected $appends = ['public_url', 'stats'];

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
