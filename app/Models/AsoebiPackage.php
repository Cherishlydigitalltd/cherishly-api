<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AsoebiPackage extends Model
{
    protected $fillable = ['event_id', 'name', 'description', 'price', 'stock', 'sort', 'is_active'];

    protected $casts = [
        'price' => 'decimal:2',
        'stock' => 'integer',
        'sort' => 'integer',
        'is_active' => 'boolean',
    ];

    public function event() { return $this->belongsTo(Event::class); }
    public function orders() { return $this->hasMany(AsoebiOrder::class); }
}
