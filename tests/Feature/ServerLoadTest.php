<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\QueueService;
use App\Support\LiveVersion;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * What keeps polling cheap on a visit day, without showing anyone stale data
 * after their own action.
 *   DB_DATABASE=tagemi_security_test php artisan test --filter=ServerLoadTest
 */
class ServerLoadTest extends TestCase
{
    use DatabaseTransactions;

    private int $eventId;
    private int $esId;
    private int $specialtyId;
    private int $appointmentId;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([1 => 'SuperAdmin', 2 => 'SimpleUser'] as $id => $name) {
            DB::table('roles')->updateOrInsert(['id' => $id], ['name' => $name]);
        }
        $this->admin = User::factory()->create(['role_id' => 1]);
        $today = QueueService::today();
        $this->eventId = DB::table('events')->insertGetId([
            'name' => 'Load', 'date' => $today, 'is_active' => 1, 'is_archived' => 0, 'nbr_of_allowed_specialties' => 1,
        ]);
        $this->specialtyId = DB::table('specialties')->insertGetId(['name' => 'S' . uniqid()]);
        $this->esId = DB::table('event_specialties')->insertGetId([
            'event_id' => $this->eventId, 'specialty_id' => $this->specialtyId, 'opened_on' => $today,
        ]);
        $dayId = DB::table('days')->insertGetId(['event_specialty_id' => $this->esId, 'day_date' => $today]);
        $hourId = DB::table('hours')->insertGetId(['day_id' => $dayId, 'time' => '09:00:00', 'max_allowed' => 10]);
        $this->appointmentId = DB::table('appointments')->insertGetId([
            'event_id' => $this->eventId, 'event_specialty_id' => $this->esId, 'day_id' => $dayId, 'hour_id' => $hourId,
            'user_id' => $this->admin->id, 'full_name' => 'زائر الأول', 'birthday' => '1990-01-01', 'state' => 'Adrar',
            'city' => 'Adrar', 'sex' => '0', 'phone' => '0555000111', 'phone2' => '0555000222', 'diseases' => '-', 'patient_id' => 1, 'orderList' => 1, 'status' => 'Present',
            'identity_key' => sha1(uniqid()), 'updated_at' => now(),
        ]);
    }

    private function setStatusInSameSecond(string $status): void
    {
        // Query builder: updated_at stays the same, as for two changes within one second
        DB::table('appointments')->where('id', $this->appointmentId)->update(['status' => $status]);
    }

    private function appointmentReads(callable $request): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $request();
        DB::disableQueryLog();

        return collect(DB::getQueryLog())->filter(fn ($q) => str_starts_with($q['query'], 'select * from `appointments`'))->count();
    }

    public function test_live_version_is_shared_for_a_few_seconds_then_fresh(): void
    {
        config(['app.live_cache_seconds' => 3]);
        Sanctum::actingAs($this->admin);
        $url = "/api/events/{$this->eventId}/live-version";

        $first = $this->getJson($url)->assertOk()->json('version');
        $this->setStatusInSameSecond('Waiting');

        $this->assertSame($first, $this->getJson($url)->json('version'));
        $this->travel(4)->seconds();
        $this->assertNotSame($first, $this->getJson($url)->json('version'));
    }

    public function test_two_changes_in_the_same_second_change_the_version(): void
    {
        $before = LiveVersion::compute($this->eventId);
        $this->setStatusInSameSecond('Waiting');
        $after = LiveVersion::compute($this->eventId);

        $this->assertNotSame($before['version'], $after['version']);
        $this->assertNotSame($before['parts']["es_{$this->esId}"], $after['parts']["es_{$this->esId}"]);
    }

    public function test_by_event_is_built_once_until_something_changes(): void
    {
        Sanctum::actingAs($this->admin);
        $url = "/api/appointments/by-event?event_id={$this->eventId}";

        $this->assertSame(1, $this->appointmentReads(fn () => $this->getJson($url)->assertOk()));
        $this->assertSame(0, $this->appointmentReads(fn () => $this->getJson($url)->assertOk()));
    }

    public function test_a_desk_sees_its_own_action_right_away_even_with_polling_cached(): void
    {
        config(['app.live_cache_seconds' => 3]);
        Sanctum::actingAs($this->admin);
        $url = "/api/appointments/by-event?event_id={$this->eventId}";

        $this->getJson("/api/events/{$this->eventId}/live-version")->assertOk();
        $this->assertSame('Present', $this->getJson($url)->json('appointments.0.status'));

        $this->setStatusInSameSecond('Completed');

        $this->assertSame('Completed', $this->getJson($url)->json('appointments.0.status'));
    }

    public function test_by_event_keeps_what_the_screens_read_and_drops_the_rest(): void
    {
        Sanctum::actingAs($this->admin);

        $row = $this->getJson("/api/appointments/by-event?event_id={$this->eventId}")->assertOk()->json('appointments.0');

        foreach (['id', 'patient_id', 'full_name', 'status', 'phone', 'is_special', 'medical_document_urls', 'event_specialty', 'day', 'hour'] as $key) {
            $this->assertArrayHasKey($key, $row);
        }
        foreach (['identity_key', 'medical_documents', 'comment', 'user_id', 'created_at'] as $key) {
            $this->assertArrayNotHasKey($key, $row);
        }
    }

    public function test_a_filtered_list_is_not_served_from_the_whole_event_list(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson("/api/appointments/by-event?event_id={$this->eventId}")->assertOk();

        $this->getJson("/api/appointments/by-event?event_id={$this->eventId}&status=Completed")
            ->assertOk()->assertJsonPath('total', 0);
    }

    public function test_hall_screen_and_stations_are_shared_for_a_few_seconds(): void
    {
        config(['app.live_cache_seconds' => 3]);
        Sanctum::actingAs($this->admin);
        $hall = "/api/public/waitinglist?event_id={$this->eventId}";
        $stations = "/api/events/{$this->eventId}/stations";

        $this->getJson($hall)->assertOk()->assertJsonPath('total_patients', 1);
        $this->getJson($stations)->assertOk()->assertJsonPath('stations.0.in_queue', 1);

        $this->setStatusInSameSecond('Completed');

        $this->getJson($hall)->assertJsonPath('total_patients', 1);
        $this->getJson($stations)->assertJsonPath('stations.0.in_queue', 1);

        $this->travel(4)->seconds();

        $this->getJson($hall)->assertJsonPath('total_patients', 0);
        $this->getJson($stations)->assertJsonPath('stations.0.in_queue', 0);
    }

    public function test_token_last_used_is_written_at_most_once_a_minute(): void
    {
        $plain = $this->admin->createToken('desk')->plainTextToken;
        $token = $this->admin->tokens()->first();
        $call = function () use ($plain) {
            $this->app['auth']->forgetGuards();
            $this->withToken($plain)->getJson("/api/events/{$this->eventId}/live-version")->assertOk();
        };

        $call();
        $firstUse = $token->fresh()->last_used_at;
        $this->assertNotNull($firstUse);

        $this->travel(30)->seconds();
        $call();
        $this->assertTrue($firstUse->eq($token->fresh()->last_used_at));

        $this->travel(31)->seconds();
        $call();
        $this->assertTrue($token->fresh()->last_used_at->gt($firstUse));
    }

    public function test_a_revoked_token_stops_working_at_once(): void
    {
        $plain = $this->admin->createToken('desk')->plainTextToken;
        $this->withToken($plain)->getJson("/api/events/{$this->eventId}/live-version")->assertOk();

        $this->admin->tokens()->delete();
        $this->app['auth']->forgetGuards();

        $this->withToken($plain)->getJson("/api/events/{$this->eventId}/live-version")->assertUnauthorized();
    }

    public function test_my_turn_polling_skips_the_duplicate_request_lock(): void
    {
        config(['app.duplicate_request_window' => 10]);
        $body = ['event_id' => $this->eventId, 'patient_id' => 1];

        $this->postJson('/api/public/my-turn', $body)->assertOk();

        $this->postJson('/api/public/my-turn', $body)->assertOk()->assertHeaderMissing('X-Duplicate-Request');
    }

    public function test_the_broken_delayers_job_is_no_longer_scheduled(): void
    {
        $commands = collect(app(Schedule::class)->events())->pluck('command')->filter()->implode(' ');

        $this->assertStringNotContainsString('delete-delayers', $commands);
    }
}
