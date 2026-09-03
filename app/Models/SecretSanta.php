<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SecretSanta extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'title',
        'description',
        'budget',
        'is_matched',
        'matched_at',
        'share_token',
    ];

    protected $casts = [
        'budget' => 'decimal:2',
        'is_matched' => 'boolean',
        'matched_at' => 'datetime',
    ];

    protected $appends = ['participants_count'];

    /* ── Accessors ── */

    public function getParticipantsCountAttribute(): int
    {
        return $this->participants()->count();
    }

    /* ── Relationships ── */

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function participants()
    {
        return $this->hasMany(SantaParticipant::class, 'santa_id');
    }
}
