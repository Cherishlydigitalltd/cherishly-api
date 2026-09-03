<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Requests\Santa\AddParticipantsRequest;
use App\Http\Requests\Santa\CreateSantaRequest;
use App\Models\SecretSanta;
use App\Models\SantaParticipant;
use App\Services\SecretSantaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SecretSantaController extends Controller
{
    public function __construct(
        private SecretSantaService $santaService
    ) {
    }

    /**
     * GET /api/santa
     */
    public function index(Request $request): JsonResponse
    {
        $santas = $this->santaService->getUserSantas($request->user());
        return ApiResponse::success('Secret Santas retrieved.', $santas);
    }

    /**
     * POST /api/santa
     */
    public function store(CreateSantaRequest $request): JsonResponse
    {
        $santa = $this->santaService->create($request->user(), $request->validated());
        return ApiResponse::success('Secret Santa created successfully.', $santa, 201);
    }

    /**
     * GET /api/santa/{id}
     */
    public function show(Request $request, SecretSanta $santa): JsonResponse
    {
        $this->authorizeOwner($request, $santa);
        $santa->load('participants.assignedTo');
        return ApiResponse::success('Secret Santa retrieved.', $santa);
    }

    /**
     * PUT /api/santa/{id}
     */
    public function update(CreateSantaRequest $request, SecretSanta $santa): JsonResponse
    {
        $this->authorizeOwner($request, $santa);
        $santa = $this->santaService->update($santa, $request->validated());
        return ApiResponse::success('Secret Santa updated successfully.', $santa);
    }

    /**
     * DELETE /api/santa/{id}
     */
    public function destroy(Request $request, SecretSanta $santa): JsonResponse
    {
        $this->authorizeOwner($request, $santa);
        $this->santaService->delete($santa);
        return ApiResponse::success('Secret Santa deleted successfully.');
    }

    /**
     * GET /api/santa/{id}/participants
     */
    public function participants(Request $request, SecretSanta $santa): JsonResponse
    {
        $this->authorizeOwner($request, $santa);
        $participants = $this->santaService->getParticipants($santa);
        return ApiResponse::success('Participants retrieved.', $participants);
    }

    /**
     * POST /api/santa/{id}/participants
     * Add participants manually
     */
    public function addParticipants(AddParticipantsRequest $request, SecretSanta $santa): JsonResponse
    {
        $this->authorizeOwner($request, $santa);
        $participants = $this->santaService->addParticipants($santa, $request->participants);
        return ApiResponse::success('Participants added successfully.', $participants, 201);
    }

    /**
     * POST /api/santa/{id}/participants/import
     * Upload CSV
     */
    public function importParticipants(Request $request, SecretSanta $santa): JsonResponse
    {
        $this->authorizeOwner($request, $santa);

        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,xlsx,xls', 'max:10240'],
        ]);

        $participants = $this->santaService->importParticipants($santa, $request->file('file'));
        return ApiResponse::success(count($participants) . ' participants imported.', ['count' => count($participants)], 201);
    }

    /**
     * DELETE /api/santa/{id}/participants/{participantId}
     */
    public function removeParticipant(Request $request, SecretSanta $santa, SantaParticipant $participant): JsonResponse
    {
        $this->authorizeOwner($request, $santa);
        $this->authorizeParticipant($participant, $santa);
        $this->santaService->removeParticipant($participant);
        return ApiResponse::success('Participant removed successfully.');
    }

    /**
     * POST /api/santa/{id}/generate-matches
     */
    public function generateMatches(Request $request, SecretSanta $santa): JsonResponse
    {
        $this->authorizeOwner($request, $santa);

        try {
            $this->santaService->generateMatches($santa);
            return ApiResponse::success('Matches generated! Participants have been notified via email.');
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage());
        }
    }

    /* ── Helper ── */

    private function authorizeOwner(Request $request, SecretSanta $santa): void
    {
        if ($santa->user_id !== $request->user()->id) {
            abort(403, 'You do not have permission to access this Secret Santa.');
        }
    }

    private function authorizeParticipant(SantaParticipant $participant, SecretSanta $santa): void
    {
        if ($participant->santa_id !== $santa->id) {
            abort(404, 'Participant not found in this Secret Santa.');
        }
    }


    /**
     * GET /api/public/santa/{token}
     * Public view — get santa info
     */
    public function publicShow(string $token): JsonResponse
    {
        $santa = SecretSanta::where('share_token', $token)->first();

        if (!$santa) {
            return ApiResponse::notFound('Secret Santa not found.');
        }

        return ApiResponse::success('Secret Santa retrieved.', [
            'id' => $santa->id,
            'title' => $santa->title,
            'cover_photo' => $santa->cover_photo,
            'is_matched' => $santa->is_matched,
            'budget' => $santa->budget,
        ]);
    }

    /**
     * POST /api/public/santa/{token}/reveal
     * Reveal match by code or email
     */
    public function revealMatch(Request $request, string $token): JsonResponse
    {
        $request->validate([
            'identifier' => ['required', 'string'],
        ]);

        $santa = SecretSanta::where('share_token', $token)->first();

        if (!$santa) {
            return ApiResponse::notFound('Secret Santa not found.');
        }

        if (!$santa->is_matched) {
            return ApiResponse::error('Matches have not been generated yet.', null, 422);
        }

        $identifier = trim($request->identifier);

        // Find participant by code or email
        $participant = $santa->participants()
            ->with('assignedTo')
            ->where(function ($q) use ($identifier) {
                $q->where('code', $identifier)
                    ->orWhere('email', $identifier);
            })
            ->first();

        if (!$participant) {
            return ApiResponse::error('No participant found with that code or email.', null, 404);
        }

        if (!$participant->assignedTo) {
            return ApiResponse::error('You have not been assigned a recipient yet.', null, 422);
        }

        return ApiResponse::success('Match revealed.', [
            'participant_name' => $participant->name,
            'recipient_name' => $participant->assignedTo->name,
            'budget' => $santa->budget,
        ]);
    }



}
