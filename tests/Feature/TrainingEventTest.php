<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\QueueService;
use App\Services\TrainingEventService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Training event: start for today, reset to a fresh state, hard delete.
 *   DB_DATABASE=tagemi_security_test php artisan test --filter=TrainingEventTest
 */
class TrainingEventTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([1 => 'SuperAdmin', 2 => 'SimpleUser', 3 => 'SpecialAdmin', 4 => 'CheckAdmin', 5 => 'RecipientAdmin', 6 => 'Doctor', 7 => 'ContentAdmin'] as $id => $name) {
            DB::table('roles')->updateOrInsert(['id' => $id], ['name' => $name]);
        }
        DB::table('users')->updateOrInsert(['id' => 1], ['name' => 'admin', 'email' => 'admin1@test.local', 'password' => bcrypt('x'), 'role_id' => 1]);

        // Only one training event may exist: start from none (rolled back after the test)
        foreach (\App\Models\Event::where('is_simulation', true)->get() as $existing) {
            app(\App\Services\TrainingEventService::class)->delete($existing);
        }

        // Make sure there are at least 8 specialties to pick from
        while (DB::table('specialties')->count() < 8) {
            DB::table('specialties')->insert(['name' => 'T' . uniqid()]);
        }

        Sanctum::actingAs(User::find(1));
    }

    private function start(): int
    {
        return $this->postJson('/api/events/training')->assertCreated()->json('event.id');
    }

    public function test_start_builds_todays_event_with_100_pending_appointments(): void
    {
        $id = $this->start();

        $event = DB::table('events')->find($id);
        $this->assertEquals(1, $event->is_simulation);
        $this->assertSame(QueueService::today(), $event->date);

        $this->assertSame(8, DB::table('event_specialties')->where('event_id', $id)->count());
        $regular = fn () => DB::table('appointments')->where('event_id', $id)->where('comment', '!=', TrainingEventService::DUPLICATE_COMMENT);
        $this->assertSame(100, $regular()->count());
        $this->assertSame(113, DB::table('appointments')->where('event_id', $id)->count());

        // Patients with 1, 2 and 3 specialties, never twice in the same one
        $perPatient = $regular()->groupBy('patient_id')
            ->selectRaw('count(*) as n, count(distinct event_specialty_id) as d')->get();
        $this->assertSame(65, $perPatient->count());
        $this->assertTrue($perPatient->every(fn ($r) => $r->n === $r->d));
        $this->assertSame([40, 15, 10], [$perPatient->where('n', 1)->count(), $perPatient->where('n', 2)->count(), $perPatient->where('n', 3)->count()]);
        $this->assertSame(113, DB::table('appointments')->where('event_id', $id)->where('status', 'Pending')->count());
        $this->assertSame(8, DB::table('days')->whereIn('event_specialty_id', DB::table('event_specialties')->where('event_id', $id)->pluck('id'))->where('day_date', QueueService::today())->count());
        $this->assertSame(113, (int) DB::table('hours')->join('days', 'days.id', '=', 'hours.day_id')->join('event_specialties', 'event_specialties.id', '=', 'days.event_specialty_id')->where('event_id', $id)->sum('counter'));
        $this->assertSame(0, DB::table('hours')->join('days', 'days.id', '=', 'hours.day_id')->join('event_specialties', 'event_specialties.id', '=', 'days.event_specialty_id')->where('event_id', $id)->whereColumn('counter', '>', 'max_allowed')->count());
    }

    public function test_training_holds_double_registrations_to_merge(): void
    {
        $id = $this->start();
        $first = fn (string $name) => (int) DB::table('appointments')->where('event_id', $id)->where('full_name', $name)->value('patient_id');
        $proposals = fn (int $patientId) => $this->getJson("/api/events/{$id}/patients/{$patientId}/duplicates")->assertOk()->json('patient_ids');

        $samir = $first('سمير بن قاسم');
        $this->assertCount(1, $proposals($samir));
        $this->assertCount(1, $proposals($first('فاطمة زهراء بلعيد')));
        $this->assertCount(1, $proposals($first('محمد أمين قاصي')));
        $this->assertCount(0, $proposals($first('عبد القادر حمدي'))); // family, not the same person
        $this->assertCount(2, $proposals($first('يوسف شريف بوعلام')));

        // Two specialties in two submissions: merged, then checked in
        $this->postJson("/api/events/{$id}/patients/{$samir}/merge", ['patient_ids' => $proposals($samir)])->assertOk();
        DB::table('event_specialties')->where('event_id', $id)->update(['opened_on' => QueueService::today()]);
        $this->postJson("/api/events/{$id}/check-in", ['patient_id' => $samir])->assertOk();
    }

    public function test_each_specialty_has_fake_doctors_covering_its_hours(): void
    {
        $id = $this->start();

        $doctors = DB::table('doctors')->where('is_simulation', true);
        $this->assertSame(12, $doctors->count());

        foreach (DB::table('event_specialties')->where('event_id', $id)->get() as $es) {
            $doctorIds = DB::table('event_specialty_doctors')->where('event_specialty_id', $es->id)->pluck('doctor_id');
            $this->assertNotEmpty($doctorIds);
            $this->assertSame(0, DB::table('doctors')->whereIn('id', $doctorIds)->where('specialty_id', '!=', $es->specialty_id)->count());

            $day = DB::table('days')->where('event_specialty_id', $es->id)->first();
            $intervals = DB::table('doctor_intervals')->where('day_id', $day->id)->get();
            $this->assertCount(count($doctorIds), $intervals);
            // Same visits per hour as the bookable places
            $this->assertSame(
                (int) DB::table('hours')->where('day_id', $day->id)->sum('max_allowed'),
                (int) $intervals->sum('visits_count')
            );
            $this->assertSame(0, DB::table('doctor_attendances')->where('day_id', $day->id)->count());
        }

        // Not in the doctors registry
        $this->getJson('/api/doctors')->assertOk()->assertJsonMissing(['is_simulation' => true]);
    }

    public function test_orthopedics_doctors_plan_one_minute_visits(): void
    {
        $first = DB::table('specialties')->orderBy('id')->first();
        DB::table('specialties')->where('id', $first->id)->update(['name' => 'طب العظام والمفاصل']);
        $id = $this->start();

        $es = DB::table('event_specialties')->where('event_id', $id)->where('specialty_id', $first->id)->first();
        $day = DB::table('days')->where('event_specialty_id', $es->id)->first();
        $this->assertSame([1], DB::table('doctor_intervals')->where('day_id', $day->id)->distinct()->pluck('visit_minutes')->all());
        $this->assertSame(600, (int) DB::table('doctor_intervals')->where('day_id', $day->id)->value('visits_count')); // 08:00-18:00

        // The other specialties keep 4 visits per hour
        $other = DB::table('doctor_intervals')->join('days', 'days.id', '=', 'doctor_intervals.day_id')
            ->join('event_specialties', 'event_specialties.id', '=', 'days.event_specialty_id')
            ->where('event_specialties.event_id', $id)->where('event_specialties.id', '!=', $es->id)
            ->distinct()->pluck('visit_minutes')->sort()->values()->all();
        $this->assertSame([15, 30], $other);
    }

    public function test_only_one_training_event_at_a_time(): void
    {
        $this->start();
        $this->postJson('/api/events/training')->assertStatus(409);
    }

    public function test_reset_discards_everything_done_during_training(): void
    {
        $id = $this->start();
        $es = DB::table('event_specialties')->where('event_id', $id)->first();

        DB::table('appointments')->where('event_id', $id)->limit(10)->update(['status' => 'Completed']);
        DB::table('event_specialties')->where('id', $es->id)->update(['opened_on' => QueueService::today()]);
        DB::table('events')->where('id', $id)->update(['date' => '2020-01-01', 'is_active' => 0]);
        $a = DB::table('appointments')->where('event_id', $id)->first();
        DB::table('appointments')->insert([
            'user_id' => 1, 'event_id' => $id, 'event_specialty_id' => $a->event_specialty_id, 'day_id' => $a->day_id, 'hour_id' => $a->hour_id,
            'full_name' => 'extra', 'birthday' => '1990-01-01', 'sex' => '0', 'phone' => '1', 'phone2' => '1', 'patient_id' => 999999, 'identity_key' => 'x',
        ]);

        $this->postJson("/api/events/{$id}/training/reset")->assertOk();

        $event = DB::table('events')->find($id);
        $this->assertSame(QueueService::today(), $event->date);
        $this->assertEquals(1, $event->is_active);
        $this->assertSame(113, DB::table('appointments')->where('event_id', $id)->count());
        $this->assertSame(113, DB::table('appointments')->where('event_id', $id)->where('status', 'Pending')->count());
        $this->assertSame(0, DB::table('event_specialties')->where('event_id', $id)->whereNotNull('opened_on')->count());
        $this->assertSame(8, DB::table('event_specialties')->where('event_id', $id)->count());
        $this->assertSame(12, DB::table('doctors')->where('is_simulation', true)->count());
    }

    public function test_delete_removes_the_event_and_everything_under_it(): void
    {
        $id = $this->start();
        $this->deleteJson("/api/events/{$id}/training")->assertOk();

        $this->assertNull(DB::table('events')->find($id));
        $this->assertSame(0, DB::table('appointments')->where('event_id', $id)->count());
        $this->assertSame(0, DB::table('event_specialties')->where('event_id', $id)->count());
        $this->assertSame(0, DB::table('doctors')->where('is_simulation', true)->count());
    }

    public function test_reset_and_delete_refuse_real_events(): void
    {
        $real = DB::table('events')->insertGetId(['name' => 'Real', 'date' => QueueService::today()]);

        $this->postJson("/api/events/{$real}/training/reset")->assertNotFound();
        $this->deleteJson("/api/events/{$real}/training")->assertNotFound();
        $this->assertNotNull(DB::table('events')->find($real));
    }

    public function test_only_the_super_admin_can_use_it(): void
    {
        $user = User::factory()->create(['role_id' => 3]);
        Sanctum::actingAs($user);

        $this->postJson('/api/events/training')->assertForbidden();
    }
}
