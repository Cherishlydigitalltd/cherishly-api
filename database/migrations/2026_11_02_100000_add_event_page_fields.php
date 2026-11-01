<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    /**
     * Turn an event into its own page (cherishlyng.com/e/<slug>) that brings
     * together the date, venue, schedule and dress code with the host's
     * registry, cash gift, wishes wall and RSVP.
     *
     * Also gives event guests private RSVP codes: their links used the guest's
     * numeric id (?guest=5), so one link let anyone read other guests'
     * details and passes, or answer for them. Same fix invitations got.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('slug', 60)->nullable()->unique()->after('share_token');
            // wedding, birthday, naming, anniversary, graduation,
            // housewarming, burial or other: sets the page's wording.
            $table->string('event_type', 20)->default('other');
            $table->string('headline', 160)->nullable();
            $table->time('start_time')->nullable()->after('event_date');
            $table->string('venue_address', 300)->nullable()->after('venue');
            $table->string('map_link', 500)->nullable();
            $table->decimal('map_lat', 10, 7)->nullable();
            $table->decimal('map_lng', 10, 7)->nullable();
            // Extra days/venues, e.g. a traditional wedding before the church
            // service: [{name, date, time, venue, address, map_link, lat, lng}].
            $table->json('parts')->nullable();
            $table->json('schedule')->nullable();
            $table->text('story')->nullable();
            $table->json('gallery')->nullable();
            $table->string('hashtag', 60)->nullable();
            $table->string('livestream_url', 500)->nullable();
            $table->string('contact_name', 120)->nullable();
            $table->string('contact_phone', 30)->nullable();
            $table->string('aso_ebi_url', 500)->nullable();
            $table->string('aso_ebi_note', 300)->nullable();
            // Sections the host has switched off.
            $table->json('hidden_sections')->nullable();
            $table->string('dress_code', 300)->nullable();
            $table->json('colours')->nullable();
            $table->string('accent_color', 7)->nullable();
            // 'invited': guests answer through their own invitation link.
            // 'open': anyone with the page link can RSVP.
            $table->string('rsvp_mode', 10)->default('invited');
            // The invitation whose guest list the page's RSVP uses.
            $table->foreignId('invitation_id')->nullable()->constrained()->nullOnDelete();
        });

        foreach (['gift_registries', 'monetary_gifts', 'memory_walls'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('event_id')->nullable()->constrained()->nullOnDelete();
            });
        }

        Schema::table('event_guests', function (Blueprint $table) {
            $table->string('rsvp_token', 40)->nullable()->unique()->after('qr_token');
        });
        DB::table('event_guests')->whereNull('rsvp_token')->orderBy('id')
            ->chunkById(500, function ($guests) {
                foreach ($guests as $guest) {
                    DB::table('event_guests')->where('id', $guest->id)
                        ->update(['rsvp_token' => Str::random(32)]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('event_guests', function (Blueprint $table) {
            $table->dropUnique(['rsvp_token']);
            $table->dropColumn('rsvp_token');
        });

        foreach (['gift_registries', 'monetary_gifts', 'memory_walls'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('event_id');
            });
        }

        Schema::table('events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invitation_id');
            $table->dropUnique(['slug']);
            $table->dropColumn([
                'slug', 'event_type', 'headline', 'start_time', 'venue_address',
                'map_link', 'map_lat', 'map_lng', 'parts', 'schedule', 'story',
                'gallery', 'hashtag', 'livestream_url', 'contact_name',
                'contact_phone', 'aso_ebi_url', 'aso_ebi_note', 'hidden_sections',
                'dress_code', 'colours', 'accent_color', 'rsvp_mode',
            ]);
        });
    }
};
