<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'wallet_id',
        'user_id',
        'type',
        'amount',
        'description',
        'reference',
        'status',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    /* ── Relationships ── */

    public function wallet()
    {
        return $this->belongsTo(Wallet::class);
    }

    /* ── Scopes ── */

    public function scopeCredits($query)
    {
        return $query->where('type', 'credit');
    }

    public function scopeDebits($query)
    {
        return $query->where('type', 'debit');
    }

    public function scopeSuccessful($query)
    {
        return $query->where('status', 'successful');
    }
}
