<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QR check-in at the venue: ushers scan guests' passes through a door link,
 * see their table, and hand over souvenirs once per pass.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invitations', function (Blueprint $t) {
            $t->string('door_code', 12)->nullable()->unique();   // the ushers' link: /door/<code>
            $t->boolean('door_enabled')->default(false);
            $t->boolean('track_souvenirs')->default(true);
        });

        Schema::table('invitation_guests', function (Blueprint $t) {
            $t->string('table_label', 20)->nullable();            // "7", "VIP 2"
            $t->unsignedSmallInteger('party_size')->nullable();    // null: 1, or 2 with a plus-one
            $t->string('checked_in_by', 60)->nullable();
            $t->timestamp('souvenirs_at')->nullable();
            $t->string('souvenirs_by', 60)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invitation_guests', function (Blueprint $t) {
            $t->dropColumn(['table_label', 'party_size', 'checked_in_by', 'souvenirs_at', 'souvenirs_by']);
        });
        Schema::table('invitations', function (Blueprint $t) {
            $t->dropUnique(['door_code']);
            $t->dropColumn(['door_code', 'door_enabled', 'track_souvenirs']);
        });
    }
};
