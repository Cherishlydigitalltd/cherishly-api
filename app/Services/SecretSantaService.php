<?php

namespace App\Services;

use App\Models\SecretSanta;
use App\Models\SantaParticipant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class SecretSantaService
{
    /* ── List ── */

    public function getUserSantas(User $user): LengthAwarePaginator
    {
        return SecretSanta::where('user_id', $user->id)
            ->withCount('participants')
            ->latest()
            ->paginate(20);
    }

    /* ── Create ── */

    public function create(User $user, array $data): SecretSanta
    {
        return SecretSanta::create([
            'user_id' => $user->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'budget' => $data['budget'] ?? null,
        ]);
    }

    /* ── Update ── */

    public function update(SecretSanta $santa, array $data): SecretSanta
    {
        $santa->update($data);
        return $santa->fresh();
    }

    /* ── Delete ── */

    public function delete(SecretSanta $santa): void
    {
        $santa->delete();
    }

    /* ── Add participants manually ── */

    public function addParticipants(SecretSanta $santa, array $participants): array
    {
        // Reset matches if adding new participants
        if ($santa->is_matched) {
            $santa->participants()->update(['assigned_to_id' => null]);
            $santa->update(['is_matched' => false, 'matched_at' => null]);
        }

        $created = [];
        foreach ($participants as $participant) {
            $created[] = SantaParticipant::create([
                'santa_id' => $santa->id,
                'name' => $participant['name'],
                'email' => $participant['email'] ?? null,
                'code' => strtoupper(substr(md5(uniqid()), 0, 6)),
            ]);
        }

        return $created;
    }

    /* ── Import from CSV ── */

    public function importParticipants(SecretSanta $santa, UploadedFile $file): array
    {
        $participants = [];
        $handle = fopen($file->getRealPath(), 'r');
        fgetcsv($handle); // skip header

        while (($row = fgetcsv($handle)) !== false) {
            if (empty($row[0]))
                continue;

            $participants[] = SantaParticipant::create([
                'santa_id' => $santa->id,
                'name' => $row[0] ?? '',
                'email' => $row[1] ?? null,
                'code' => strtoupper(substr(md5(uniqid()), 0, 6)),
            ]);
        }

        fclose($handle);
        return $participants;
    }

    /* ── Remove participant ── */

    public function removeParticipant(SantaParticipant $participant): void
    {
        $participant->delete();
    }

    /* ── Generate matches (Secret Santa algorithm) ── */

    public function generateMatches(SecretSanta $santa): bool
    {
        return DB::transaction(function () use ($santa) {
            $participants = $santa->participants()->get();

            if ($participants->count() < 2) {
                throw new \RuntimeException('At least 2 participants are required to generate matches.');
            }

            // Shuffle participants for random assignment
            $givers = $participants->shuffle()->values();
            $receivers = $givers->slice(1)->concat($givers->slice(0, 1))->values();

            // Assign each giver to a receiver
            foreach ($givers as $index => $giver) {
                $giver->update(['assigned_to_id' => $receivers[$index]->id]);
            }

            $santa->update([
                'is_matched' => true,
                'matched_at' => now(),
            ]);

            // Send notification emails
            foreach ($givers as $index => $giver) {
                if ($giver->email) {
                    \App\Jobs\SendSantaMatchEmail::dispatch($giver, $receivers[$index], $santa);
                }
            }

            return true;
        });
    }

    /* ── Get participants ── */

    public function getParticipants(SecretSanta $santa): \Illuminate\Database\Eloquent\Collection
    {
        return $santa->participants()
            ->with('assignedTo')
            ->get();
    }
}
