<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use App\Services\TrainingEventService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * «أثناء الحدث» visit durations: mean vs planned, fastest / longest, and the
 * estimated end per specialty and per doctor. Uses the training event (one
 * doctor at 15 min in the second specialty, two at 30 min in the third).
 *   DB_DATABASE=tagemi_security_test php artisan test --filter=VisitTimingStatsTest
 */
class VisitTimingStatsTest extends TestCase
{
    use DatabaseTransactions;

    private int $eventId;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([1 => 'SuperAdmin', 2 => 'SimpleUser', 3 => 'SpecialAdmin', 4 => 'CheckAdmin', 5 => 'RecipientAdmin', 6 => 'Doctor', 7 => 'ContentAdmin'] as $id => $name) {
            DB::table('roles')->updateOrInsert(['id' => $id], ['name' => $name]);
        }
        DB::table('users')->updateOrInsert(['id' => 1], ['name' => 'admin', 'email' => 'admin1@test.local', 'password' => bcrypt('x'), 'role_id' => 1]);

        foreach (Event::where('is_simulation', true)->get() as $existing) {
            app(TrainingEventService::class)->delete($existing);
        }
        while (DB::table('specialties')->count() < 8) {
            DB::table('specialties')->insert(['name' => 'T' . uniqid()]);
        }
        $this->eventId = app(TrainingEventService::class)->start()->id;

        Sanctum::actingAs(User::find(1));
    }

    /** [event specialty id, day id, doctor ids] of the n-th specialty. */
    private function specialty(int $n): array
    {
        $es = DB::table('event_specialties')->where('event_id', $this->eventId)->orderBy('id')->skip($n)->first();
        $day = DB::table('days')->where('event_specialty_id', $es->id)->first();
        $doctors = DB::table('event_specialty_doctors')->where('event_specialty_id', $es->id)->orderBy('doctor_id')->pluck('doctor_id')->all();

        return [$es->id, $day->id, $doctors];
    }

    private function arrive(int $doctorId, int $dayId): void
    {
        DB::table('doctor_attendances')->insert(['doctor_id' => $doctorId, 'day_id' => $dayId, 'arrived_at' => now()->subHour(), 'created_at' => now(), 'updated_at' => now()]);
    }

    /** Marks $n appointments of the specialty as seen by $doctorId in $seconds each. */
    private function complete(int $esId, int $doctorId, array $seconds): void
    {
        $ids = DB::table('appointments')->where('event_specialty_id', $esId)->where('status', 'Pending')->orderBy('id')->limit(count($seconds))->pluck('id');
        foreach ($ids as $i => $id) {
            DB::table('appointments')->where('id', $id)->update([
                'status' => 'Completed', 'doctor_id' => $doctorId, 'visit_seconds' => $seconds[$i],
                'entered_at' => now()->subMinutes(30), 'completed_at' => now()->subMinutes(20),
            ]);
        }
    }

    private function timing(): array
    {
        return $this->getJson("/api/events/{$this->eventId}/doctor-visits")->assertOk()->json('timing');
    }

    public function test_mean_planned_and_fastest_and_slowest_specialty(): void
    {
        [$es0, $day0, [$solo]] = $this->specialty(1);
        [$es2, $day2, [$a, $b]] = $this->specialty(2);
        $this->arrive($solo, $day0);
        $this->complete($es0, $solo, [600, 1200]);   // 15-minute plan
        $this->complete($es2, $a, [1800]);           // 30-minute plan
        $this->complete($es2, $b, [3000]);

        $t = $this->timing();

        $this->assertSame(4, $t['treated']);
        $this->assertSame(1650, $t['mean_seconds']);                 // (600+1200+1800+3000) / 4
        $this->assertSame(1350, $t['planned_mean_seconds']);       // (900 * 2 + 1800 * 2) / 4
        // By specialty mean: 900 s (second specialty) vs 2400 s (third)
        $this->assertSame(900, $t['fastest_specialty']['mean_seconds']);
        $this->assertSame(2400, $t['longest_specialty']['mean_seconds']);
        $this->assertNotNull($t['longest_specialty']['specialty']);

        $s0 = collect($t['specialties'])->firstWhere('event_specialty_id', $es0);
        $this->assertSame(900, $s0['mean_seconds']);
        $this->assertSame(2, $s0['treated']);
        $this->assertSame(600, $s0['fastest_seconds']);
        $this->assertSame(1200, $s0['longest_seconds']);
    }

    public function test_estimated_end_follows_the_pace_of_working_doctors(): void
    {
        [$es0, $day0, [$solo]] = $this->specialty(1);
        $this->arrive($solo, $day0);
        $this->complete($es0, $solo, [600]);          // 10 minutes per visit

        $t = $this->timing();
        $s0 = collect($t['specialties'])->firstWhere('event_specialty_id', $es0);
        $remaining = DB::table('appointments')->where('event_specialty_id', $es0)->where('status', '!=', 'Completed')->count();

        $this->assertSame($remaining, $s0['remaining']);
        $this->assertSame(1, $s0['working']);
        $expected = now()->setTimezone(\App\Services\QueueService::TIMEZONE)->addMinutes(10 * $remaining)->format('H:i');
        $this->assertContains($s0['estimated_end'], [$expected, now()->setTimezone(\App\Services\QueueService::TIMEZONE)->addMinutes(10 * $remaining + 1)->format('H:i')]);

        $doctor = collect($t['doctors'])->firstWhere('doctor_id', $solo);
        $this->assertSame($s0['estimated_end'], $doctor['estimated_end']);

        // Nobody arrived in the third specialty: the two planned doctors (30 min each) share it
        [$es2] = $this->specialty(2);
        $s2 = collect($t['specialties'])->firstWhere('event_specialty_id', $es2);
        $this->assertSame(2, $s2['working']);
        $this->assertNotNull($s2['estimated_end']);
    }
}
