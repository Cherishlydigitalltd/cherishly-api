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
    ];

    protected $casts = [
        'balance' => 'decimal:2',
        'available_balance' => 'decimal:2',
        'pending_settlement' => 'decimal:2',
        'total_received' => 'decimal:2',
        'total_withdrawn' => 'decimal:2',
    ];

    protected $appends = ['pending_amount'];

    public function getPendingAmountAttribute(): float
    {
        return (float) $this->balance - (float) $this->available_balance;
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