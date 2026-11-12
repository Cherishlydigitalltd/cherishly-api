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

    /**
     * The owner's BVN check, which lives on their wallet (shared with
     * customer withdrawals). A BVN belongs to a person, so for a business
     * account it's the owner's or director's, not the company's.
     */
    public function bvnVerified(): bool
    {
        return (bool) $this->wallet()?->bvn_verified;
    }

    /** BVN status for the vendor's own screens: never the number itself. */
    public function identity(): array
    {
        $wallet = $this->wallet();
        return [
            'bvn_verified' => (bool) $wallet?->bvn_verified,
            'bvn_last4' => $wallet?->bvn_verified ? $wallet->bvnLast4() : null,
            'bvn_full_name' => $wallet?->bvn_verified ? $wallet->bvn_full_name : null,
        ];
    }

    /** Looked up directly so the wallet isn't attached to (and sent with) the user. */
    private function wallet(): ?Wallet
    {
        return $this->user_id ? Wallet::where('user_id', $this->user_id)->first() : null;
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
