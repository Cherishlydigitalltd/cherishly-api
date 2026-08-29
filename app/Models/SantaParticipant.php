<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SantaParticipant extends Model
{
    use HasFactory;

    protected $fillable = [
        'santa_id',
        'name',
        'email',
        'assigned_to_id',
    ];

    /* ── Relationships ── */

    public function santa()
    {
        return $this->belongsTo(SecretSanta::class, 'santa_id');
    }

    public function assignedTo()
    {
        return $this->belongsTo(SantaParticipant::class, 'assigned_to_id');
    }

    public function assignedBy()
    {
        return $this->hasOne(SantaParticipant::class, 'assigned_to_id');
    }
}
