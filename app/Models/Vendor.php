<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vendor extends Model
{
    public const STATES = [
        'Abia', 'Adamawa', 'Akwa Ibom', 'Anambra', 'Bauchi', 'Bayelsa', 'Benue', 'Borno',
        'Cross River', 'Delta', 'Ebonyi', 'Edo', 'Ekiti', 'Enugu', 'FCT', 'Gombe', 'Imo',
        'Jigawa', 'Kaduna', 'Kano', 'Katsina', 'Kebbi', 'Kogi', 'Kwara', 'Lagos', 'Nasarawa',
        'Niger', 'Ogun', 'Ondo', 'Osun', 'Oyo', 'Plateau', 'Rivers', 'Sokoto', 'Taraba',
        'Yobe', 'Zamfara',
    ];

    public const CATEGORIES = [
        'Kitchen', 'Electronics', 'Furniture', 'Decor', 'Home', 'Fashion', 'Beauty',
        'Sports', 'Books', 'Baby', 'Food & Drinks', 'Experiences', 'Other',
    ];

    protected $fillable = [
        'user_id', 'business_name', 'contact_name', 'phone', 'email', 'address', 'city',
        'state', 'cac_number', 'logo', 'description', 'delivery_states', 'bank_code',
        'bank_name', 'account_number', 'account_name', 'status', 'status_reason', 'approved_at',
    ];

    protected $casts = [
        'delivery_states' => 'array',
        'approved_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function products()
    {
        return $this->hasMany(VendorProduct::class);
    }

    public function orders()
    {
        return $this->hasMany(VendorOrder::class);
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function hasBank(): bool
    {
        return $this->account_number && $this->bank_code;
    }

    public function deliversTo(?string $state): bool
    {
        return $state && in_array($state, $this->delivery_states ?? [], true);
    }
}
