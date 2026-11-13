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
        'slug', 'event_type', 'headline', 'start_time', 'venue_address',
        'map_link', 'map_lat', 'map_lng', 'parts', 'schedule', 'story',
        'gallery', 'hashtag', 'livestream_url', 'contact_name', 'contact_phone',
        'aso_ebi_url', 'aso_ebi_note', 'hidden_sections',
        'dress_code', 'colours', 'accent_color', 'rsvp_mode', 'invitation_id',
        'spray_enabled', 'spray_code', 'spray_show_total', 'spray_show_leaders', 'spray_ended_at',
    ];

    protected $casts = [
        'event_date' => 'date',
        'is_active'  => 'boolean',
        'schedule'   => 'array',
        'colours'    => 'array',
        'parts'      => 'array',
        'gallery'    => 'array',
        'hidden_sections' => 'array',
        'map_lat'    => 'float',
        'map_lng'    => 'float',
        'spray_enabled' => 'boolean',
        'spray_show_total' => 'boolean',
        'spray_show_leaders' => 'boolean',
        'spray_ended_at' => 'datetime',
    ];

    protected $appends = ['page_url'];

    /** Words an event page can't be called, so /e/<slug> stays unambiguous. */
    public const TYPES = ['wedding', 'birthday', 'naming', 'anniversary', 'graduation', 'housewarming', 'burial', 'other'];

    /** Sections a host can switch off. */
    public const SECTIONS = ['countdown', 'story', 'schedule', 'dress_code', 'gallery', 'gifts', 'wishes', 'rsvp', 'aso_ebi'];

    public const GALLERY_MAX = 12;

    public const RESERVED_SLUGS = ['new', 'edit', 'admin', 'api', 'preview', 'search', 'cherishly'];

    public function user() { return $this->belongsTo(User::class); }

    public function guests() { return $this->hasMany(EventGuest::class); }

    /* What the event page brings together. */
    public function invitation() { return $this->belongsTo(Invitation::class); }
    public function registries() { return $this->hasMany(GiftRegistry::class); }
    public function monetaryGifts() { return $this->hasMany(MonetaryGift::class); }
    public function walls() { return $this->hasMany(MemoryWall::class); }

    /* Digital spray */
    public function sprayGuests() { return $this->hasMany(SprayGuest::class); }
    public function sprayTopups() { return $this->hasMany(SprayTopup::class); }
    public function sprays() { return $this->hasMany(Spray::class); }

    public function getPageUrlAttribute(): ?string
    {
        return $this->slug ? config('app.frontend_url') . '/e/' . $this->slug : null;
    }

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
