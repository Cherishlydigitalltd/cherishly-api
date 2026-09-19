<?php

namespace App\Services;

use App\Models\GiftRegistry;
use App\Models\Contribution;
use App\Models\Invitation;
use App\Models\InvitationGuest;
use App\Models\User;

class StatsService
{
    public function getPlatformStats(): array
    {
        return [
            'registries_created' => GiftRegistry::count(),
            'gifts_delivered' => Contribution::where('payment_status', 'successful')->count(),
            'happy_celebrants' => User::count(),
            'invitations_sent' => InvitationGuest::count(),
        ];
    }
}