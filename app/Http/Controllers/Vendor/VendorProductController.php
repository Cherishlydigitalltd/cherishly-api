<?php

namespace App\Http\Controllers\Vendor;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Vendor;
use App\Models\VendorProduct;
use App\Services\AssetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A vendor's gift items. New items, and edits to the name, description,
 * category, price or photos of a live item, wait for admin approval. While an
 * edit waits, the live item keeps showing as it was.
 */
class VendorProductController extends Controller
{
    public function __construct(private AssetService $assets)
    {
    }

    /** GET /vendor/products */
    public function index(Request $request): JsonResponse
    {
        $vendor = $request->attributes->get('vendor');
        $products = $vendor->products()
            ->when($request->query('status'), fn ($q, $status) => $status === 'review'
                ? $q->where(fn ($q) => $q->where('status', 'pending')->orWhereNotNull('pending_changes'))
                : $q->where('status', $status))
            ->when($request->query('search'), fn ($q, $s) => $q->where('name', 'ilike', "%{$s}%"))
            ->latest()
            ->paginate(20);

        return ApiResponse::success('Products retrieved.', $products);
    }

    /** GET /vendor/products/{product} */
    public function show(Request $request, VendorProduct $product): JsonResponse
    {
        $this->own($request, $product);
        return ApiResponse::success('Product retrieved.', $product);
    }

    /** POST /vendor/products (multipart: images[]) */
    public function store(Request $request): JsonResponse
    {
        $vendor = $request->attributes->get('vendor');
        abort_if($vendor->status === 'suspended', 403, 'Your vendor account is suspended.');

        $data = $this->validated($request);
        $images = $this->images($request, []);
        if (!$images) {
            return ApiResponse::error('Add at least one photo.', statusCode: 422);
        }

        $product = $vendor->products()->create($data + [
            'images' => $images,
            'status' => 'pending',
        ]);

        return ApiResponse::success('Item submitted. It will show to celebrants once approved.', $product, 201);
    }

    /**
     * POST /vendor/products/{product} (multipart)
     * keep_images[]: current photo URLs to keep, in order; images[]: new photos.
     */
    public function update(Request $request, VendorProduct $product): JsonResponse
    {
        $this->own($request, $product);
        abort_if($request->attributes->get('vendor')->status === 'suspended', 403, 'Your vendor account is suspended.');

        $data = $this->validated($request);
        $keep = array_values(array_intersect(
            (array) $request->input('keep_images', []),
            [...$product->images, ...($product->pending_changes['images'] ?? [])],
        ));
        $images = $this->images($request, $keep);
        if (!$images) {
            return ApiResponse::error('Add at least one photo.', statusCode: 422);
        }

        // Stock and the on/off switch apply straight away.
        $direct = array_intersect_key($data, array_flip(['stock', 'is_active']));
        $reviewed = array_intersect_key($data, array_flip(VendorProduct::REVIEWED_FIELDS)) + ['images' => $images];

        if ($product->status === 'approved') {
            $changes = array_filter($reviewed, fn ($value, $key) => $this->differs($product->{$key}, $value), ARRAY_FILTER_USE_BOTH);
            $product->update($direct + [
                'pending_changes' => $changes ?: null,
                'status_reason' => $changes ? null : $product->status_reason,
            ]);
            $message = $changes ? 'Changes submitted for approval. The item stays live as it was until then.' : 'Item updated.';
        } else {
            $product->update($direct + $reviewed + ['status' => 'pending', 'status_reason' => null, 'pending_changes' => null]);
            $message = 'Item submitted for approval.';
        }

        return ApiResponse::success($message, $product->fresh());
    }

    /** PUT /vendor/products/{product}/active: pause or show again */
    public function toggle(Request $request, VendorProduct $product): JsonResponse
    {
        $this->own($request, $product);
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $product->update($data);
        return ApiResponse::success($data['is_active'] ? 'Item is showing again.' : 'Item paused.', $product->fresh());
    }

    /** DELETE /vendor/products/{product}. Gifts already in registries keep their copy. */
    public function destroy(Request $request, VendorProduct $product): JsonResponse
    {
        $this->own($request, $product);
        $product->delete();
        return ApiResponse::success('Item removed.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category' => ['required', Rule::in(Vendor::CATEGORIES)],
            'price' => ['required', 'numeric', 'min:500', 'max:50000000'],
            'stock' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'images' => ['nullable', 'array', 'max:' . VendorProduct::MAX_IMAGES],
            'images.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);
        unset($data['images']); // uploaded separately, see images()
        return $data;
    }

    /** Kept photos plus newly uploaded ones, up to the limit. */
    private function images(Request $request, array $keep): array
    {
        $files = $request->file('images', []);
        abort_if(count($keep) + count($files) > VendorProduct::MAX_IMAGES, 422,
            'You can add up to ' . VendorProduct::MAX_IMAGES . ' photos.');

        foreach ($files as $file) {
            $keep[] = $this->assets->upload($file, 'vendor-products');
        }
        return $keep;
    }

    private function differs($current, $new): bool
    {
        if (is_array($current) || is_array($new)) {
            return $current !== $new;
        }
        if (is_numeric($current) && is_numeric($new)) {
            return (float) $current !== (float) $new;
        }
        return (string) $current !== (string) $new;
    }

    private function own(Request $request, VendorProduct $product): void
    {
        abort_if($product->vendor_id !== $request->attributes->get('vendor')->id, 404);
    }
}
