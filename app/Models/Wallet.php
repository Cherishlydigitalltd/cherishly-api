<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Wallet extends Model
{
    protected $fillable = [
        'user_id',
        'balance',
        'available_balance',
        'pending_settlement',
        'total_received',
        'total_withdrawn',
        'bank_name',
        'account_number',
        'account_name',
        'bank_code',
        'bvn_verified',
        'bvn_encrypted',
        'bvn_hash',
        'bvn_full_name',
        'bvn_date_of_birth',
        'bvn_address',
        'bvn_state',
        'bvn_lga',
        'bvn_reference',
        'bvn_verified_at',
    ];

    /** The BVN never leaves the API in an ordinary response; see bvnLast4(). */
    protected $hidden = ['bvn_encrypted', 'bvn_hash'];

    protected $casts = [
        'balance' => 'decimal:2',
        'available_balance' => 'decimal:2',
        'pending_settlement' => 'decimal:2',
        'total_received' => 'decimal:2',
        'total_withdrawn' => 'decimal:2',
        'bvn_verified' => 'boolean',
        'bvn_verified_at' => 'datetime',
        'bvn_date_of_birth' => 'date:Y-m-d',
    ];

    protected $appends = ['pending_amount'];

    public function getPendingAmountAttribute(): float
    {
        return (float) $this->balance - (float) $this->available_balance;
    }

    /** The stored BVN (decrypted), or null. */
    public function bvn(): ?string
    {
        if (!$this->bvn_encrypted) {
            return null;
        }
        try {
            return \Illuminate\Support\Facades\Crypt::decryptString($this->bvn_encrypted);
        } catch (\Throwable) {
            return null;
        }
    }

    public function bvnLast4(): ?string
    {
        $bvn = $this->bvn();
        return $bvn ? substr($bvn, -4) : null;
    }

    /* ── Relationships ── */

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }
}