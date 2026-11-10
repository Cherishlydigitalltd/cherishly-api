<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\AccountDeletionRequest;
use App\Services\AccountDeletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Account deletion requests from users. An admin reviews each one, then
 * deletes the account (if nothing is blocking it) or rejects the request.
 */
class AdminDeletionRequestController extends Controller
{
    public function __construct(private AccountDeletionService $deletion)
    {
    }

    /** GET /admin/deletion-requests?status=pending|completed|rejected|cancelled */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', 'in:pending,completed,rejected,cancelled']]);

        $query = AccountDeletionRequest::with(['user:id,phone,deleted_at', 'processor:id,first_name,last_name'])
            ->latest();
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $page = $query->paginate(25);

        // For pending requests, say up front whether the account can be deleted.
        $page->getCollection()->transform(function (AccountDeletionRequest $r) {
            $r->setAttribute('blockers', $r->status === 'pending' && $r->user && !$r->user->trashed()
                ? $this->deletion->blockers($r->user)
                : []);
            return $r;
        });

        return ApiResponse::success('Deletion requests retrieved.', [
            'page' => $page,
            'pending_count' => AccountDeletionRequest::where('status', 'pending')->count(),
        ]);
    }

    /** PUT /admin/deletion-requests/{deletionRequest}/complete — deletes the account. */
    public function complete(Request $request, AccountDeletionRequest $deletionRequest): JsonResponse
    {
        if ($deletionRequest->status !== 'pending') {
            return ApiResponse::error('This request has already been dealt with.', null, 422);
        }

        $user = $deletionRequest->user;
        if ($user && !$user->trashed()) {
            try {
                $this->deletion->delete($user, 'admin');
            } catch (\RuntimeException $e) {
                return ApiResponse::error('Can’t delete yet: ' . $e->getMessage(), null, 422);
            }
        }

        $deletionRequest->update([
            'status' => 'completed',
            'admin_note' => $request->input('note'),
            'processed_by' => $request->user()->id,
            'processed_at' => now(),
        ]);

        return ApiResponse::success('Account deleted.');
    }

    /** PUT /admin/deletion-requests/{deletionRequest}/reject — keeps the account. */
    public function reject(Request $request, AccountDeletionRequest $deletionRequest): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:1000']]);

        if ($deletionRequest->status !== 'pending') {
            return ApiResponse::error('This request has already been dealt with.', null, 422);
        }

        $deletionRequest->update([
            'status' => 'rejected',
            'admin_note' => $data['note'],
            'processed_by' => $request->user()->id,
            'processed_at' => now(),
        ]);

        return ApiResponse::success('Request rejected. The account stays open.');
    }
}
