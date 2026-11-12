<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Checks a BVN with Scapa (thirdparty.scapa.io, POST /api/ScapaMiddleware/verify_bvn)
 * and confirms it belongs to the Cherishly user.
 */
class BvnService
{
    /**
     * @return array{success: bool, message?: string, identity?: array}
     */
    public function verify(User $user, string $bvn): array
    {
        $url = rtrim((string) config('services.scapa.url'), '/');
        $key = (string) config('services.scapa.api_key');
        if ($url === '' || $key === '') {
            Log::error('BVN verification is not configured (SCAPA_URL / SCAPA_API_KEY).');
            return ['success' => false, 'message' => 'BVN verification is temporarily unavailable. Please try again later.'];
        }

        try {
            $response = Http::withHeaders(['XApiKey' => $key, 'Accept' => 'application/json'])
                ->connectTimeout(10)->timeout(30)
                ->post($url . '/api/ScapaMiddleware/verify_bvn', [
                    'client_id' => (string) config('services.scapa.client_id'),
                    'bvn_number' => $bvn,
                    'phone_number' => $user->phone ?: '',
                ]);
        } catch (\Throwable $e) {
            report($e);
            return ['success' => false, 'message' => 'We couldn’t reach the BVN service. Please try again in a minute.'];
        }

        $body = $response->json() ?? [];
        $data = $body['data'] ?? null;

        if (!$response->successful() || empty($body['status']) || !is_array($data)
            || strtoupper((string) ($data['verificationStatus'] ?? '')) !== 'VERIFIED') {
            Log::warning('BVN not verified', [
                'user_id' => $user->id,
                'http' => $response->status(),
                'message' => $body['statusMessage'] ?? null,
            ]);
            return ['success' => false, 'message' => 'We couldn’t verify that BVN. Please check the number and try again.'];
        }

        if (strtoupper((string) ($data['watchlisted'] ?? 'NO')) !== 'NO') {
            Log::warning('BVN is watchlisted', ['user_id' => $user->id, 'reference' => $data['requestReference'] ?? null]);
            return ['success' => false, 'message' => 'We couldn’t complete your verification. Please contact support@cherishlyng.com.'];
        }

        $info = $data['personalInfo'] ?? [];
        if (!$this->namesMatch($user, $info)) {
            Log::warning('BVN name mismatch', ['user_id' => $user->id, 'reference' => $data['requestReference'] ?? null]);
            return ['success' => false, 'message' => 'The name on this BVN doesn’t match the name on your Cherishly account.'];
        }

        $home = $data['residentialInfo'] ?? [];

        return ['success' => true, 'identity' => [
            'full_name' => $info['fullName'] ?? trim(($info['firstName'] ?? '') . ' ' . ($info['lastName'] ?? '')),
            'date_of_birth' => $this->date($info['dateOfBirth'] ?? null),
            'address' => $home['residentialAddress'] ?? null,
            'state' => $home['stateOfResidence'] ?? null,
            'lga' => $home['lgaOfResidence'] ?? null,
            'reference' => $data['requestReference'] ?? null,
        ]];
    }

    /** Keyed hash, so we can spot the same BVN on another account. */
    public static function hash(string $bvn): string
    {
        return hash_hmac('sha256', $bvn, (string) config('app.key'));
    }

    /** The user's first and last name must both appear among the BVN's names, in any order. */
    private function namesMatch(User $user, array $info): bool
    {
        $words = fn (?string $s) => array_filter(preg_split('/[^A-Z]+/', strtoupper((string) $s)));
        $bvnNames = array_merge(
            $words($info['firstName'] ?? null),
            $words($info['middleName'] ?? null),
            $words($info['lastName'] ?? null),
            $words($info['fullName'] ?? null),
        );
        $first = $words($user->first_name);
        $last = $words($user->last_name);

        return $first && $last
            && array_intersect($first, $bvnNames)
            && array_intersect($last, $bvnNames);
    }

    private function date(?string $value): ?string
    {
        $ts = $value ? strtotime($value) : false;
        return $ts ? date('Y-m-d', $ts) : null;
    }
}
