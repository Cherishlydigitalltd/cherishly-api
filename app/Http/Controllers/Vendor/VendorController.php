<?php

namespace App\Http\Controllers\Vendor;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Vendor;
use App\Models\VendorOrder;
use App\Services\AssetService;
use App\Services\GatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The vendor's own profile: applying, business details and bank account. */
class VendorController extends Controller
{
    public function __construct(
        private AssetService $assets,
        private GatewayService $gateway,
    ) {
    }

    /** GET /vendor/options: states and categories for the forms. */
    public function options(): JsonResponse
    {
        return ApiResponse::success('Options retrieved.', [
            'states' => Vendor::STATES,
            'categories' => Vendor::CATEGORIES,
        ]);
    }

    /** GET /vendor/me: profile (or null) plus a summary for the dashboard. */
    public function me(Request $request): JsonResponse
    {
        $vendor = $request->user()->vendor;
        if (!$vendor) {
            return ApiResponse::success('Not a vendor yet.', ['vendor' => null]);
        }

        $orders = VendorOrder::where('vendor_id', $vendor->id);
        return ApiResponse::success('Vendor retrieved.', [
            'vendor' => $vendor,
            'summary' => [
                'products' => $vendor->products()->count(),
                'products_live' => $vendor->products()->listed()->count(),
                'products_in_review' => $vendor->products()->where(fn ($q) => $q->where('status', 'pending')->orWhereNotNull('pending_changes'))->count(),
                'orders_to_act_on' => (clone $orders)->whereIn('status', ['placed', 'accepted'])->count(),
                'orders_open' => (clone $orders)->whereIn('status', [...VendorOrder::OPEN, 'delivered', 'disputed'])->count(),
                'earned' => (float) (clone $orders)->where('payout_status', 'paid')->sum('vendor_amount'),
                'to_be_paid' => (float) (clone $orders)->where(fn ($q) => $q
                    ->whereIn('status', [...VendorOrder::OPEN, 'delivered', 'disputed'])
                    ->orWhere(fn ($q) => $q->where('status', 'completed')->where('payout_status', '!=', 'paid')))
                    ->sum('vendor_amount'),
            ],
        ]);
    }

    /** POST /vendor/apply: register as a vendor, or re-apply after a rejection. */
    public function apply(Request $request): JsonResponse
    {
        $user = $request->user();
        $vendor = $user->vendor()->first();
        if ($vendor && $vendor->status !== 'rejected') {
            return ApiResponse::error('You have already registered as a vendor.', statusCode: 422);
        }

        $data = $this->validateProfile($request, logoRequired: false);
        $data['logo'] = $this->uploadLogo($request, $vendor?->logo);

        $vendor = Vendor::updateOrCreate(['user_id' => $user->id], $data + [
            'status' => 'pending',
            'status_reason' => null,
        ]);

        return ApiResponse::success('Thanks! We’ll review your details and let you know once you’re approved.', $vendor, 201);
    }

    /** POST /vendor/profile (multipart, for the logo) */
    public function update(Request $request): JsonResponse
    {
        $vendor = $request->user()->vendor;
        $data = $this->validateProfile($request, logoRequired: false);
        $data['logo'] = $this->uploadLogo($request, $vendor->logo);
        $vendor->update($data);

        return ApiResponse::success('Profile updated.', $vendor->fresh());
    }

    /** PUT /vendor/bank: checked with the bank before saving. */
    public function bank(Request $request): JsonResponse
    {
        $data = $request->validate([
            'bank_code' => ['required', 'string', 'max:20'],
            'bank_name' => ['required', 'string', 'max:100'],
            'account_number' => ['required', 'digits:10'],
        ]);

        $resolved = $this->gateway->resolveBankAccount($data['account_number'], $data['bank_code']);
        if (!($resolved['success'] ?? false) || empty($resolved['account_name'])) {
            return ApiResponse::error('We couldn’t verify that account. Check the number and bank.', statusCode: 422);
        }

        $vendor = $request->user()->vendor;
        $vendor->update($data + ['account_name' => $resolved['account_name']]);

        // Payouts that failed for lack of a bank account can go through now.
        $marketplace = app(\App\Services\MarketplaceService::class);
        $vendor->orders()->where('status', 'completed')->where('payout_status', 'failed')->get()
            ->each(fn ($order) => $marketplace->payVendor($order));

        return ApiResponse::success('Bank account saved: ' . $resolved['account_name'], $vendor->fresh());
    }

    private function validateProfile(Request $request, bool $logoRequired): array
    {
        $data = $request->validate([
            'business_name' => ['required', 'string', 'max:200'],
            'contact_name' => ['required', 'string', 'max:200'],
            'phone' => ['required', 'string', 'max:30'],
            'email' => ['required', 'email', 'max:200'],
            'address' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:100'],
            'state' => ['required', Rule::in(Vendor::STATES)],
            'cac_number' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:1000'],
            'delivery_states' => ['required', 'array', 'min:1'],
            'delivery_states.*' => [Rule::in(Vendor::STATES)],
            'logo' => [$logoRequired ? 'required' : 'nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);
        $data['delivery_states'] = array_values(array_unique($data['delivery_states']));
        unset($data['logo']);
        return $data;
    }

    private function uploadLogo(Request $request, ?string $current): ?string
    {
        if (!$request->hasFile('logo')) {
            return $current;
        }
        return $current
            ? $this->assets->replace($current, $request->file('logo'), 'vendors')
            : $this->assets->upload($request->file('logo'), 'vendors');
    }
}
