<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SprayGuest extends Model
{
    protected $fillable = [
        'event_id', 'token', 'display_name', 'is_anonymous', 'message', 'message_hidden', 'email',
        'loaded', 'sprayed', 'bvn_hash', 'bvn_last4', 'bvn_full_name', 'bvn_reference', 'bvn_verified_at',
    ];

    protected $hidden = ['token', 'bvn_hash'];

    protected $casts = [
        'is_anonymous' => 'boolean',
        'message_hidden' => 'boolean',
        'loaded' => 'decimal:2',
        'sprayed' => 'decimal:2',
        'bvn_verified_at' => 'datetime',
    ];

    public function event() { return $this->belongsTo(Event::class); }
    public function topups() { return $this->hasMany(SprayTopup::class); }
    public function sprays() { return $this->hasMany(Spray::class); }

    /** What's left to spray. */
    public function balance(): float
    {
        return max(0, round((float) $this->loaded - (float) $this->sprayed, 2));
    }

    /** The name the big screen shows. */
    public function screenName(): string
    {
        return $this->is_anonymous || !$this->display_name ? 'Anonymous' : $this->display_name;
    }
}
