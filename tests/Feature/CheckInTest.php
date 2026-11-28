<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Invitation;
use App\Models\InvitationGuest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * QR check-in: guests show their pass, ushers scan it through the door link,
 * check the party in and hand over souvenirs once; the host watches live.
 */
class CheckInTest extends TestCase
{
    use RefreshDatabase;

    private User $host;
    private Invitation $invitation;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array', 'app.frontend_url' => 'https://cherishlyng.test']);
        $this->host = User::create([
            'first_name' => 'Tolu', 'last_name' => 'Bello', 'email' => 'tolu@x.test',
            'password' => 'secret-pass', 'is_email_verified' => true,
        ]);
        $this->invitation = Invitation::create(['user_id' => $this->host->id, 'title' => 'Tolu & Femi']);
    }

    private function guest(array $extra = []): InvitationGuest
    {
        return $this->invitation->guests()->create($extra + [
            'full_name' => 'Adaeze Okafor', 'email' => Str::random(5) . '@x.test', 'rsvp_status' => 'attending',
        ]);
    }

    /** Switch the door link on as the host; returns the code. */
    private function openDoor(): string
    {
        Sanctum::actingAs($this->host);
        $res = $this->putJson("/api/invitations/{$this->invitation->id}/checkin", ['door_enabled' => true])->assertOk();
        $code = $res->json('data.door_code');
        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{8}$/', $code);
        $this->assertSame("https://cherishlyng.test/door/{$code}", $res->json('data.door_url'));

        return $code;
    }

    public function test_guest_sees_their_pass_with_table_and_event(): void
    {
        Event::create(['user_id' => $this->host->id, 'title' => "Tolu & Femi's Wedding", 'share_token' => Str::random(16),
            'event_date' => '2026-12-19', 'venue' => 'Eko Hotel', 'invitation_id' => $this->invitation->id]);
        $g = $this->guest(['has_plus_one' => true, 'table_label' => '7']);

        $this->getJson('/api/public/passes/' . $g->qr_token)->assertOk()
            ->assertJsonPath('data.guest.full_name', 'Adaeze Okafor')
            ->assertJsonPath('data.guest.table', '7')
            ->assertJsonPath('data.guest.party_size', 2)
            ->assertJsonPath('data.event.title', "Tolu & Femi's Wedding")
            ->assertJsonPath('data.event.venue', 'Eko Hotel')
            ->assertJsonPath('data.pass_url', 'https://cherishlyng.test/pass/' . $g->qr_token)
            ->assertJsonMissingPath('data.guest.email');

        $this->getJson('/api/public/passes/nope')->assertNotFound();
    }

    public function test_door_link_is_off_until_the_host_switches_it_on(): void
    {
        $this->getJson('/api/public/door/ABCDEFGH')->assertNotFound();
        $code = $this->openDoor();
        $this->getJson("/api/public/door/{$code}")->assertOk()->assertJsonPath('data.event.title', 'Tolu & Femi');
        $this->getJson("/api/public/door/" . strtolower($code))->assertOk();

        // Switching off, or issuing a new link, kills the old one.
        $this->putJson("/api/invitations/{$this->invitation->id}/checkin", ['door_enabled' => true, 'new_code' => true])->assertOk();
        $this->getJson("/api/public/door/{$code}")->assertNotFound();
        $this->putJson("/api/invitations/{$this->invitation->id}/checkin", ['door_enabled' => false])->assertOk()
            ->assertJsonPath('data.door_url', null);
        $this->assertArrayNotHasKey('door_code', $this->invitation->fresh()->toArray());
    }

    public function test_usher_scans_checks_in_and_a_second_scan_is_refused(): void
    {
        $g = $this->guest(['has_plus_one' => true, 'table_label' => '7']);
        $this->guest(['full_name' => 'Tobi Alade']);
        $code = $this->openDoor();

        // The scanner reads the full pass URL from the QR.
        $this->postJson("/api/public/door/{$code}/scan", ['scanned' => 'https://cherishlyng.com/pass/' . $g->qr_token])->assertOk()
            ->assertJsonPath('data.guest.table', '7')
            ->assertJsonPath('data.guest.party_size', 2)
            ->assertJsonPath('data.guest.checked_in', false);

        $this->withHeader('X-Usher-Name', 'Bayo')
            ->postJson("/api/public/door/{$code}/guests/{$g->qr_token}/check-in", ['souvenirs' => true])->assertOk()
            ->assertJsonPath('data.result', 'checked_in')
            ->assertJsonPath('data.guest.checked_in_by', 'Bayo')
            ->assertJsonPath('data.guest.souvenirs_by', 'Bayo')
            ->assertJsonPath('data.counts.arrived', 2)
            ->assertJsonPath('data.counts.expected', 3)
            ->assertJsonPath('data.counts.souvenirs', 2);

        // Same pass again, at another door: no second check-in, no second souvenir.
        $this->withHeader('X-Usher-Name', 'Chioma')
            ->postJson("/api/public/door/{$code}/guests/{$g->qr_token}/check-in", ['souvenirs' => true])->assertOk()
            ->assertJsonPath('data.result', 'already_checked_in')
            ->assertJsonPath('data.guest.checked_in_by', 'Bayo');
        $this->postJson("/api/public/door/{$code}/guests/{$g->qr_token}/souvenirs")->assertOk()
            ->assertJsonPath('data.result', 'already_given');
        $this->assertSame('Bayo', $g->fresh()->souvenirs_by);
    }

    public function test_passes_from_another_event_dont_work_at_this_door(): void
    {
        $other = Invitation::create(['user_id' => $this->host->id, 'title' => 'Other party']);
        $stranger = $other->guests()->create(['full_name' => 'Gate Crasher', 'rsvp_status' => 'attending']);
        $code = $this->openDoor();

        $this->postJson("/api/public/door/{$code}/scan", ['scanned' => $stranger->qr_token])->assertNotFound();
        $this->postJson("/api/public/door/{$code}/guests/{$stranger->qr_token}/check-in")->assertNotFound();
        $this->assertFalse($stranger->fresh()->checked_in);
    }

    public function test_souvenirs_can_be_given_later_and_tracking_can_be_off(): void
    {
        $g = $this->guest();
        $code = $this->openDoor();
        $this->postJson("/api/public/door/{$code}/guests/{$g->qr_token}/check-in")->assertJsonPath('data.result', 'checked_in');
        $this->assertNull($g->fresh()->souvenirs_at);
        $this->postJson("/api/public/door/{$code}/guests/{$g->qr_token}/souvenirs")->assertJsonPath('data.result', 'given');

        $this->putJson("/api/invitations/{$this->invitation->id}/checkin", ['track_souvenirs' => false])->assertOk();
        $g2 = $this->guest(['full_name' => 'Tobi Alade']);
        $this->postJson("/api/public/door/{$code}/guests/{$g2->qr_token}/check-in", ['souvenirs' => true])->assertOk();
        $this->assertNull($g2->fresh()->souvenirs_at);
    }

    public function test_usher_can_search_by_name(): void
    {
        $this->guest(['full_name' => 'Adaeze Okafor', 'table_label' => '7']);
        $this->guest(['full_name' => 'Tobi Alade']);
        $code = $this->openDoor();

        $this->getJson("/api/public/door/{$code}/search?q=ada")->assertOk()
            ->assertJsonCount(1, 'data.guests')
            ->assertJsonPath('data.guests.0.table', '7');
        $this->getJson("/api/public/door/{$code}/search?q=%25")->assertOk()->assertJsonCount(0, 'data.guests');
    }

    public function test_host_seats_guests_and_sees_the_live_dashboard(): void
    {
        $a = $this->guest(['full_name' => 'Adaeze Okafor']);
        $b = $this->guest(['full_name' => 'Tobi Alade']);
        $this->guest(['full_name' => 'Said No', 'rsvp_status' => 'not_attending']);
        $code = $this->openDoor();

        $this->putJson("/api/invitations/{$this->invitation->id}/guests/{$a->id}/seat", ['table' => ' 7 ', 'party_size' => 3])->assertOk()
            ->assertJsonPath('data.guest.table', '7')->assertJsonPath('data.guest.party_size', 3);
        $this->putJson("/api/invitations/{$this->invitation->id}/guests/{$b->id}/seat", ['table' => '7'])->assertOk();
        $this->getJson("/api/invitations/{$this->invitation->id}/seating")->assertOk()->assertJsonCount(2, 'data.guests');

        $this->withHeader('X-Usher-Name', 'Bayo')->postJson("/api/public/door/{$code}/guests/{$a->qr_token}/check-in");

        Sanctum::actingAs($this->host);
        $this->getJson("/api/invitations/{$this->invitation->id}/checkin")->assertOk()
            ->assertJsonPath('data.counts.expected', 4)
            ->assertJsonPath('data.counts.arrived', 3)
            ->assertJsonPath('data.counts.passes_waiting', 1)
            ->assertJsonPath('data.arrivals.0.full_name', 'Adaeze Okafor')
            ->assertJsonPath('data.waiting.0.full_name', 'Tobi Alade')
            ->assertJsonPath('data.tables.0.table', '7')
            ->assertJsonPath('data.tables.0.expected', 4)
            ->assertJsonPath('data.tables.0.arrived', 3)
            ->assertJsonPath('data.ushers.0.name', 'Bayo');
    }

    public function test_other_users_cant_see_or_change_the_check_in(): void
    {
        $g = $this->guest();
        Sanctum::actingAs(User::create([
            'first_name' => 'Ola', 'last_name' => 'X', 'email' => 'ola@x.test', 'password' => 'secret-pass', 'is_email_verified' => true,
        ]));
        $this->getJson("/api/invitations/{$this->invitation->id}/checkin")->assertForbidden();
        $this->putJson("/api/invitations/{$this->invitation->id}/checkin", ['door_enabled' => true])->assertForbidden();
        $this->putJson("/api/invitations/{$this->invitation->id}/guests/{$g->id}/seat", ['table' => '1'])->assertForbidden();
    }
}
