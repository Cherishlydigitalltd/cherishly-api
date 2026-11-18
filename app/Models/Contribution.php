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
        'bvn_hash',
        'bvn_last4',
        'bvn_full_name',
        'bvn_reference',
        'bvn_verified_at',
        'payment_method',
        'payment_reference',
        'payment_status',
        'is_anonymous',
        'payment_meta',
        'gross_amount',
        'net_amount',
        'fee_amount',
        'fee_rate',
        'fee_bearer',
    ];

    /** BVN data never leaves the API whole; admins see bvn_last4 and bvn_full_name. */
    protected $hidden = ['bvn', 'bvn_hash'];

    protected $casts = [
        'bvn_verified_at' => 'datetime',
        'amount' => 'decimal:2',
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

    protected function bvn(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            get: fn(?string $value) => $value ? \Illuminate\Support\Facades\Crypt::decryptString($value) : null,
            set: fn(?string $value) => $value ? \Illuminate\Support\Facades\Crypt::encryptString($value) : null,
        );
    }
}
