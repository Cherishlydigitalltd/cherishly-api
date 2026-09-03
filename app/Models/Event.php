<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class Event extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id', 'title', 'description', 'cover_photo',
        'share_token', 'event_date', 'venue', 'is_active',
    ];

    protected $casts = [
        'event_date' => 'date',
        'is_active'  => 'boolean',
    ];

    public function user() { return $this->belongsTo(User::class); }

    public function guests() { return $this->hasMany(EventGuest::class); }

    public function getGuestsCountAttribute(): int { return $this->guests()->count(); }

    public function getAttendingCountAttribute(): int
    {
        return $this->guests()->where('rsvp_status', 'attending')->count();
    }

    public function getCheckedInCountAttribute(): int
    {
        return $this->guests()->whereNotNull('checked_in_at')->count();
    }

    public function getPublicUrlAttribute(): string
    {
        return config('app.frontend_url') . '/event/' . $this->share_token;
    }
}
