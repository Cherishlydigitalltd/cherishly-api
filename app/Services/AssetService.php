<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AssetService
{
    private string $baseUrl;
    private string $apiKey;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.assets.url', 'https://assets.cherishlyng.com'), '/');
        $this->apiKey = config('services.assets.key', '');
    }

    /**
     * Upload a file to the asset server
     * Returns the full URL or throws an exception
     */
    public function upload(UploadedFile $file, string $folder = 'general'): string
    {
        $response = Http::withHeaders([
            'X-API-Key' => $this->apiKey,
        ])->attach(
                'file',
                file_get_contents($file->getRealPath()),
                $file->getClientOriginalName()
            )->post("{$this->baseUrl}/upload", [
                    'folder' => $folder,
                ]);

        if (!$response->successful()) {
            Log::error('Asset upload failed', [
                'status' => $response->status(),
                'response' => $response->json(),
            ]);
            throw new \RuntimeException('Failed to upload file. Please try again.');
        }

        $data = $response->json();

        if (!$data['status']) {
            throw new \RuntimeException($data['message'] ?? 'Upload failed.');
        }

        return $data['data']['url'];
    }

    /**
     * Delete a file from the asset server
     */
    public function delete(string $path): bool
    {
        try {
            // Extract relative path from full URL if needed
            $path = $this->extractPath($path);

            $response = Http::withHeaders([
                'X-API-Key' => $this->apiKey,
            ])->delete("{$this->baseUrl}/delete/{$path}");

            return $response->successful() && $response->json('status');
        } catch (\Exception $e) {
            Log::error('Asset delete failed', ['path' => $path, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Upload and replace — deletes old file then uploads new one
     */
    public function replace(?string $oldUrl, UploadedFile $newFile, string $folder = 'general'): string
    {
        $newUrl = $this->upload($newFile, $folder);

        // Delete old file after successful upload
        if ($oldUrl) {
            $this->delete($oldUrl);
        }

        return $newUrl;
    }

    /**
     * Extract relative path from full URL
     */
    private function extractPath(string $url): string
    {
        if (str_starts_with($url, $this->baseUrl)) {
            $path = substr($url, strlen($this->baseUrl));
            $path = ltrim($path, '/');
            // Remove "uploads/" prefix for the delete endpoint
            $path = preg_replace('/^uploads\//', '', $path);
        }

        return $url;
    }
}
