<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SprayTopup extends Model
{
    protected $fillable = [
        'event_id', 'spray_guest_id', 'amount', 'gross_amount', 'net_amount', 'fee_amount', 'fee_rate',
        'fee_bearer', 'payment_reference', 'payment_status', 'payment_meta', 'paid_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'gross_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'fee_amount' => 'decimal:2',
        'fee_rate' => 'decimal:2',
        'payment_meta' => 'array',
        'paid_at' => 'datetime',
    ];

    public function event() { return $this->belongsTo(Event::class); }
    public function guest() { return $this->belongsTo(SprayGuest::class, 'spray_guest_id'); }
}
