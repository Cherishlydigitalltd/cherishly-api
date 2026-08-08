<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Wallet extends Model
{
    protected $fillable = [
        'user_id',
        'balance',
        'total_received',
        'bank_name',
        'account_number',
        'account_name',
        'bank_code',
    ];

    protected $casts = [
        'balance'        => 'decimal:2',
        'total_received' => 'decimal:2',
    ];

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
