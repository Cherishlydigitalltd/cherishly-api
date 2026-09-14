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
        'gross_amount',      
        'net_amount',        
        'fee_amount',        
        'fee_rate',         
        'description',
        'reference',
        'status',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'gross_amount' => 'decimal:2',  
        'net_amount' => 'decimal:2', 
        'fee_amount' => 'decimal:2',  
        'fee_rate' => 'decimal:2',  
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
