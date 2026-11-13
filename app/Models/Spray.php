<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Spray extends Model
{
    protected $fillable = ['event_id', 'spray_guest_id', 'amount', 'notes', 'is_final'];

    protected $casts = [
        'amount' => 'decimal:2',
        'is_final' => 'boolean',
    ];

    public function event() { return $this->belongsTo(Event::class); }
    public function guest() { return $this->belongsTo(SprayGuest::class, 'spray_guest_id'); }
}
