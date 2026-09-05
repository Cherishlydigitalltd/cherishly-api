<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\CatalogGift;
use App\Services\AssetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminCatalogController extends Controller
{
    public function __construct(private AssetService $assetService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = CatalogGift::latest();
        if ($search = $request->query('search')) {
            $query->where('name', 'ilike', "%{$search}%")
                ->orWhere('category', 'ilike', "%{$search}%");
        }
        return ApiResponse::success('Catalog gifts retrieved.', $query->paginate(20));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'price' => ['required', 'numeric', 'min:0'],
            'category' => ['nullable', 'string', 'max:100'],
            'image' => ['nullable', 'image'],
        ]);

        $imageUrl = null;
        if ($request->hasFile('image')) {
            $imageUrl = $this->assetService->upload($request->file('image'), 'catalog');
        }

        $gift = CatalogGift::create([...$data, 'image' => $imageUrl]);
        return ApiResponse::success('Catalog gift created.', $gift, 201);
    }

    public function update(Request $request, CatalogGift $catalogGift): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'category' => ['nullable', 'string', 'max:100'],
            'image' => ['nullable', 'image'],
        ]);

        if ($request->hasFile('image')) {
            $data['image'] = $this->assetService->replace(
                $catalogGift->image,
                $request->file('image'),
                'catalog'
            );
        }

        $catalogGift->update($data);
        return ApiResponse::success('Catalog gift updated.', $catalogGift->fresh());
    }

    public function destroy(CatalogGift $catalogGift): JsonResponse
    {
        if ($catalogGift->image) {
            $this->assetService->delete($catalogGift->image);
        }
        $catalogGift->delete();
        return ApiResponse::success('Catalog gift deleted.');
    }
}