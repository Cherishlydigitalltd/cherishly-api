<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Contribution extends Model
{
    use HasFactory;

    protected $fillable = [
        'gift_id',
        'donor_name',
        'donor_email',
        'donor_phone',
        'amount',
        'bvn',
        'payment_method',
        'payment_reference',
        'payment_status',
        'is_anonymous',
        'payment_meta',
    ];

    protected $casts = [
        'amount'       => 'decimal:2',
        'is_anonymous' => 'boolean',
        'payment_meta' => 'array',
    ];

    /* ── Relationships ── */

    public function gift()
    {
        return $this->belongsTo(Gift::class);
    }

    /* ── Scopes ── */

    public function scopeSuccessful($query)
    {
        return $query->where('payment_status', 'successful');
    }

    public function scopePending($query)
    {
        return $query->where('payment_status', 'pending');
    }
}
