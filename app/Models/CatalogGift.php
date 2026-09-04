<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CatalogGift extends Model
{
    protected $fillable = ['name', 'description', 'price', 'category', 'image'];
}