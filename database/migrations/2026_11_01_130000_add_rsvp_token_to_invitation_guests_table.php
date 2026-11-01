<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    /**
     * RSVP links used the guest's numeric id (?guest=5), so anyone with one
     * invitation link could count up and answer for other guests. Each guest
     * now gets an unguessable code for their link instead. It is separate
     * from qr_token so a forwarded invitation doesn't hand over the entry pass.
     */
    public function up(): void
    {
        Schema::table('invitation_guests', function (Blueprint $table) {
            $table->string('rsvp_token', 40)->nullable()->unique()->after('qr_token');
        });

        DB::table('invitation_guests')->whereNull('rsvp_token')->orderBy('id')
            ->chunkById(500, function ($guests) {
                foreach ($guests as $guest) {
                    DB::table('invitation_guests')
                        ->where('id', $guest->id)
                        ->update(['rsvp_token' => Str::random(32)]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('invitation_guests', function (Blueprint $table) {
            $table->dropUnique(['rsvp_token']);
            $table->dropColumn('rsvp_token');
        });
    }
};
