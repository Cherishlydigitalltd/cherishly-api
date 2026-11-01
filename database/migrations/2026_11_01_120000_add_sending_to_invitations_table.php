<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Track when invitations were sent, and when a scheduled send is due.
     * Before this, the web asked for a schedule but nothing stored or
     * acted on it, and nothing recorded that invitations had gone out.
     */
    public function up(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->timestamp('scheduled_at')->nullable()->after('rsvp_deadline');
            $table->timestamp('sent_at')->nullable()->after('scheduled_at');
            $table->index(['scheduled_at', 'sent_at']);
        });

        // Invitations sent before this was tracked: if any guest has replied
        // or checked in, it certainly went out. The rest stay drafts, so the
        // host can send again and guests get the new private RSVP links.
        DB::table('invitations')
            ->whereNull('sent_at')
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('invitation_guests')
                    ->whereColumn('invitation_guests.invitation_id', 'invitations.id')
                    ->where(function ($w) {
                        $w->whereNotNull('rsvp_responded_at')->orWhere('checked_in', true);
                    });
            })
            ->update(['sent_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->dropIndex(['scheduled_at', 'sent_at']);
            $table->dropColumn(['scheduled_at', 'sent_at']);
        });
    }
};
