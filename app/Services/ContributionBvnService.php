<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Large gifts (registry contributions and cash gifts) need a verified BVN.
 *
 * The limit is an admin setting (contribution_bvn_threshold) and counts what
 * the same email has already given to the same gift, so splitting one big
 * gift into several smaller ones doesn't skip the check.
 */
class ContributionBvnService
{
    public function __construct(private BvnService $bvn) {}

    public function threshold(): float
    {
        return (float) setting('contribution_bvn_threshold', 5000000);
    }

    /**
     * Throws a 422 when a BVN is needed and missing or doesn't check out.
     *
     * @param  Builder  $previous  this gift's contributions (any status)
     * @return array  BVN columns to store on the contribution; empty when not needed
     */
    public function check(float $amount, Builder $previous, array $data, array $log = []): array
    {
        $threshold = $this->threshold();
        if ($threshold <= 0) {
            return [];
        }

        $email = strtolower(trim((string) ($data['donor_email'] ?? '')));
        $byThisGiver = (clone $previous)->whereRaw('LOWER(donor_email) = ?', [$email]);

        // Paid gifts, plus ones still being paid for (so two tabs can't split it).
        $soFar = (float) (clone $byThisGiver)
            ->where(fn ($q) => $q->where('payment_status', 'successful')
                ->orWhere(fn ($q) => $q->where('payment_status', 'pending')->where('created_at', '>', now()->subHour())))
            ->sum('amount');

        if ($soFar + $amount <= $threshold) {
            return [];
        }

        $bvn = preg_replace('/\D/', '', (string) ($data['bvn'] ?? ''));
        if ($bvn === '') {
            throw ValidationException::withMessages(['needs_bvn' => [
                'Gifts above ₦' . number_format($threshold) . ' need a verified BVN. It’s a legal requirement for large payments.',
            ]]);
        }

        $first = trim((string) ($data['bvn_first_name'] ?? ''));
        $last = trim((string) ($data['bvn_last_name'] ?? ''));
        if ($first === '' || $last === '') {
            throw ValidationException::withMessages(['bvn_first_name' => [
                'Enter your first and last name exactly as they are on your BVN.',
            ]]);
        }

        $hash = BvnService::hash($bvn);

        // Already checked this BVN for this giver on this gift: don't pay Scapa twice.
        $earlier = (clone $byThisGiver)->where('bvn_hash', $hash)->whereNotNull('bvn_verified_at')
            ->latest('bvn_verified_at')->first();
        if ($earlier) {
            return [
                'bvn_hash' => $hash,
                'bvn_last4' => substr($bvn, -4),
                'bvn_full_name' => $earlier->bvn_full_name,
                'bvn_reference' => $earlier->bvn_reference,
                'bvn_verified_at' => now(),
            ];
        }

        $result = $this->bvn->verifyName($bvn, $first, $last, $log + ['type' => 'contribution']);
        if (!$result['success']) {
            throw ValidationException::withMessages(['bvn' => [$result['message']]]);
        }

        return [
            'bvn_hash' => $hash,
            'bvn_last4' => substr($bvn, -4),
            'bvn_full_name' => $result['identity']['full_name'] ?? null,
            'bvn_reference' => $result['identity']['reference'] ?? null,
            'bvn_verified_at' => now(),
        ];
    }
}
