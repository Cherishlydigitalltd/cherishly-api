<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Wish extends Model
{
    use HasFactory;

    protected $fillable = [
        'wall_id',
        'name',
        'message',
        'is_anonymous',
    ];

    protected $casts = [
        'is_anonymous' => 'boolean',
    ];

    protected $appends = ['display_name'];

    /* ── Accessors ── */

    public function getDisplayNameAttribute(): string
    {
        return $this->is_anonymous ? 'Anonymous' : $this->name;
    }

    /* ── Relationships ── */

    public function wall()
    {
        return $this->belongsTo(MemoryWall::class, 'wall_id');
    }
}
