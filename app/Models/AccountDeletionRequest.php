<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountDeletionRequest extends Model
{
    protected $fillable = [
        'user_id', 'name', 'email', 'reason', 'source', 'status',
        'admin_note', 'processed_by', 'processed_at',
    ];

    protected $casts = ['processed_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function processor()
    {
        return $this->belongsTo(Admin::class, 'processed_by');
    }
}
