<?php

namespace Tests\Feature;

use App\Models\Doctor;
use App\Models\EventSpecialty;
use App\Models\User;
use App\Services\QueueService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Doctors' arrival/departure on the visit day: the first arrival opens the
 * specialty's queue, the last departure closes it.
 *   DB_DATABASE=tagemi_security_test php artisan test
 */
class DoctorAttendanceTest extends TestCase
{
    use DatabaseTransactions;

    private int $specialtyId;
    private int $esId;
    private int $eventId;
    private Doctor $drA;
    private Doctor $drB;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([1 => 'SuperAdmin', 2 => 'SimpleUser', 4 => 'CheckAdmin'] as $id => $name) {
            DB::table('roles')->updateOrInsert(['id' => $id], ['name' => $name]);
        }
        // Booking code (training patients too) stores user_id = 1; CI starts from an empty database
        DB::table('users')->updateOrInsert(['id' => 1], ['name' => 'admin', 'email' => 'admin1@test.local', 'password' => bcrypt('x'), 'role_id' => 1]);
        $this->specialtyId = DB::table('specialties')->insertGetId(['name' => 'عيون' . uniqid()]);
        $this->drA = $this->doctor('أ');
        $this->drB = $this->doctor('ب');

        $this->admin = User::create(['name' => 'a', 'email' => uniqid('a', true) . '@test.local', 'password' => 'password123']);
        $this->admin->forceFill(['role_id' => 1])->save();
        Sanctum::actingAs($this->admin);

        $today = QueueService::today();
        $day = fn (Doctor $d, string $start, string $end) => ['doctor_id' => $d->id, 'days' => [['day_date' => $today, 'intervals' => [
            ['start_time' => $start, 'end_time' => $end, 'visit_minutes' => 20],
        ]]]];
        $this->eventId = $this->postJson('/api/events', [
            'name' => 'زيارة', 'date' => $today,
            'specialties' => [['id' => $this->specialtyId, 'schedule' => [$day($this->drA, '08:00', '12:00'), $day($this->drB, '10:00', '13:00')]]],
        ])->assertCreated()->json('event.id');
        $this->esId = DB::table('event_specialties')->where('event_id', $this->eventId)->value('id');
    }

    private function doctor(string $name): Doctor
    {
        return Doctor::create([
            'first_name' => $name, 'family_name' => 'طبيب', 'phone' => '07' . random_int(10000000, 99999999),
            'wilaya_code' => '16', 'specialty_id' => $this->specialtyId, 'sector' => 'public', 'is_active' => true,
        ]);
    }

    private function isOpen(): bool
    {
        return EventSpecialty::find($this->esId)->isOpenToday();
    }

    public function test_first_arrival_opens_and_last_departure_closes(): void
    {
        $today = $this->getJson("/api/events/{$this->eventId}/doctors-today")->assertOk()->json('specialties.0.doctors');
        $this->assertCount(2, $today);
        $this->assertFalse($this->isOpen());

        $this->postJson("/api/event-specialties/{$this->esId}/doctors/{$this->drA->id}/arrive")->assertOk();
        $this->assertTrue($this->isOpen());
        $this->postJson("/api/event-specialties/{$this->esId}/doctors/{$this->drA->id}/arrive")->assertStatus(409);

        $this->postJson("/api/event-specialties/{$this->esId}/doctors/{$this->drB->id}/arrive")->assertOk();
        $this->postJson("/api/event-specialties/{$this->esId}/doctors/{$this->drA->id}/leave")->assertOk()->assertJsonPath('is_open', true);
        $this->assertTrue($this->isOpen());

        $this->postJson("/api/event-specialties/{$this->esId}/doctors/{$this->drB->id}/leave")->assertOk()->assertJsonPath('is_open', false);
        $this->assertFalse($this->isOpen());

        $row = collect($this->getJson("/api/events/{$this->eventId}/doctors-today")->json('specialties.0.doctors'))->firstWhere('doctor_id', $this->drA->id);
        $this->assertNotNull($row['arrived_at']);
        $this->assertNotNull($row['left_at']);
        $this->assertFalse($row['present']);
    }

    public function test_unscheduled_doctor_and_other_roles_are_refused(): void
    {
        $other = $this->doctor('ج');
        $this->postJson("/api/event-specialties/{$this->esId}/doctors/{$other->id}/arrive")->assertStatus(422);
        $this->postJson("/api/event-specialties/{$this->esId}/doctors/{$this->drA->id}/leave")->assertStatus(409);

        $checkAdmin = User::create(['name' => 'c', 'email' => uniqid('c', true) . '@test.local', 'password' => 'password123']);
        $checkAdmin->forceFill(['role_id' => 4, 'event_id' => $this->eventId])->save();
        Sanctum::actingAs($checkAdmin);
        $this->postJson("/api/event-specialties/{$this->esId}/doctors/{$this->drA->id}/arrive")->assertForbidden();
    }

    public function test_history_lists_participations_with_times(): void
    {
        $this->postJson("/api/event-specialties/{$this->esId}/doctors/{$this->drA->id}/arrive")->assertOk();
        $this->postJson("/api/event-specialties/{$this->esId}/doctors/{$this->drA->id}/leave")->assertOk();

        $history = $this->getJson("/api/doctors/{$this->drA->id}/history")->assertOk();
        $history->assertJsonPath('summary.events', 1)->assertJsonPath('summary.days_present', 1)->assertJsonPath('summary.planned_visits', 12);
        $history->assertJsonPath('days.0.event_name', 'زيارة')->assertJsonPath('days.0.intervals.0', '08:00–12:00');
        $this->assertNotNull($history->json('days.0.arrived_at'));
        $this->assertNotNull($history->json('days.0.left_at'));
    }

    public function test_opening_adds_a_doctor_not_planned_in_the_event(): void
    {
        $drC = $this->doctor('ج');
        $url = "/api/event-specialties/{$this->esId}/doctors";

        $this->postJson($url, ['doctor_ids' => [$drC->id], 'end_time' => '00:00', 'visit_minutes' => 20])->assertStatus(422);
        $this->postJson($url, ['doctor_ids' => [$drC->id], 'end_time' => '23:59', 'visit_minutes' => 20])->assertOk();

        $this->assertTrue($this->isOpen());
        $c = collect($this->getJson("/api/events/{$this->eventId}/doctors-today")->json('specialties.0.doctors'))
            ->firstWhere('doctor_id', $drC->id);
        $this->assertTrue($c['present']);
        $this->assertStringEndsWith('23:59', $c['intervals'][0]);

        // A planned doctor in the list just arrives (no second interval); twice is harmless
        $this->postJson($url, ['doctor_ids' => [$drA = $this->drA->id, $drC->id], 'end_time' => '23:59', 'visit_minutes' => 20])->assertOk();
        $this->assertSame(1, DB::table('doctor_intervals')->where('doctor_id', $drA)->count());
        $this->assertSame(1, DB::table('doctor_intervals')->where('doctor_id', $drC->id)->count());

        // Booking hours are not changed by a doctor added on the day
        $this->assertSame(0, DB::table('hours')->join('days', 'days.id', '=', 'hours.day_id')
            ->where('days.event_specialty_id', $this->esId)->where('hours.time', '>=', '13:00:00')->count());
    }

    public function test_training_event_specialty_opens_with_added_doctor_who_can_see_patients(): void
    {
        foreach (\App\Models\Event::where('is_simulation', true)->get() as $existing) {
            app(\App\Services\TrainingEventService::class)->delete($existing);
        }
        while (DB::table('specialties')->count() < 8) {
            DB::table('specialties')->insert(['name' => 'T' . uniqid()]);
        }
        $event = app(\App\Services\TrainingEventService::class)->start();
        $es = EventSpecialty::where('event_id', $event->id)->orderBy('id')->first();
        $doctor = $this->doctor('د');

        $this->postJson("/api/event-specialties/{$es->id}/doctors", ['doctor_ids' => [$doctor->id], 'end_time' => '23:59', 'visit_minutes' => 15])->assertOk();
        $this->assertTrue($es->fresh()->isOpenToday());

        // The added doctor is present; the training event's own doctors have not arrived yet
        $present = collect($this->getJson("/api/event-specialties/{$es->id}/doctors-present")->assertOk()->json('doctors'));
        $this->assertSame([$doctor->id], $present->where('present', true)->pluck('doctor_id')->all());
        $this->assertCount(count(\App\Services\TrainingEventService::DOCTORS[0]) + 1, $present);
    }
}
