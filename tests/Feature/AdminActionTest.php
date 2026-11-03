<?php

namespace Tests\Feature;

use App\Models\AdminAction;
use App\Models\Appointment;
use App\Models\User;
use App\Services\QueueService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminActionTest extends TestCase
{
    use DatabaseTransactions;

    private int $eventId;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([1 => 'SuperAdmin', 2 => 'SimpleUser', 3 => 'SpecialAdmin', 4 => 'CheckAdmin', 5 => 'RecipientAdmin', 6 => 'Doctor', 7 => 'ContentAdmin', 8 => 'PatientManager', 9 => 'Organizer'] as $id => $name) {
            DB::table('roles')->updateOrInsert(['id' => $id], ['name' => $name]);
        }
        DB::table('users')->updateOrInsert(['id' => 1], ['name' => 'admin', 'email' => 'admin1@test.local', 'password' => bcrypt('x'), 'role_id' => 1]);
        $this->eventId = DB::table('events')->insertGetId([
            'name' => 'Audit', 'date' => QueueService::today(), 'is_active' => 1, 'is_archived' => 0, 'nbr_of_allowed_specialties' => 3,
        ]);
    }

    private function staff(int $role, string $email = null): User
    {
        $user = User::create(['name' => 'staff ' . $role, 'email' => $email ?? uniqid('s', true) . '@test.local', 'password' => 'password123']);
        $user->forceFill(['role_id' => $role, 'event_id' => $this->eventId])->save();

        return $user;
    }

    private function appointment(): Appointment
    {
        $specialtyId = DB::table('specialties')->insertGetId(['name' => 'Eyes' . uniqid()]);
        $esId = DB::table('event_specialties')->insertGetId(['event_id' => $this->eventId, 'specialty_id' => $specialtyId]);
        $dayId = DB::table('days')->insertGetId(['event_specialty_id' => $esId, 'day_date' => Carbon::now(QueueService::TIMEZONE)->toDateString()]);
        $hourId = DB::table('hours')->insertGetId(['day_id' => $dayId, 'time' => '08:00:00', 'max_allowed' => 50, 'counter' => 1]);

        return Appointment::create([
            'user_id' => 1, 'event_id' => $this->eventId, 'event_specialty_id' => $esId, 'day_id' => $dayId, 'hour_id' => $hourId,
            'full_name' => 'Patient A', 'birthday' => '1980-01-01', 'sex' => '0', 'phone' => '0555123456', 'phone2' => '0666000000',
            'patient_id' => 8100, 'specialty_order' => 1, 'status' => 'Pending',
        ]);
    }

    public function test_staff_changes_are_recorded_with_who_when_and_patient(): void
    {
        $appointment = $this->appointment();
        $special = $this->staff(User::ROLE_SPECIAL_ADMIN);
        Sanctum::actingAs($special);

        $this->deleteJson("/api/appointments/{$appointment->id}")->assertOk();

        $row = AdminAction::latest('id')->first();
        $this->assertSame('AppointmentController@destroy', $row->action);
        $this->assertSame($special->id, $row->user_id);
        $this->assertSame($this->eventId, $row->event_id);
        $this->assertSame(8100, $row->subject['patient_id']);
        $this->assertSame(200, $row->status);
        $this->assertNotNull($row->created_at);
    }

    public function test_refused_actions_and_logins_are_recorded_without_passwords(): void
    {
        $this->staff(User::ROLE_RECIPIENT_ADMIN, 'desk@test.local');
        $this->postJson('/api/login', ['email' => 'desk@test.local', 'password' => 'wrong-one'])->assertStatus(422);

        $row = AdminAction::latest('id')->first();
        $this->assertSame('AuthController@login', $row->action);
        $this->assertSame(422, $row->status);
        $this->assertSame('***', $row->input['password']);

        // Organizer is refused every change: still recorded
        Sanctum::actingAs($this->staff(User::ROLE_ORGANIZER));
        $this->postJson("/api/events/{$this->eventId}/check-in", ['patient_id' => 1])->assertForbidden();
        $this->assertSame(403, AdminAction::latest('id')->first()->status);
    }

    public function test_reads_and_patients_are_not_recorded(): void
    {
        Sanctum::actingAs($this->staff(User::ROLE_SUPER_ADMIN));
        $before = AdminAction::count();
        $this->getJson("/api/events/{$this->eventId}")->assertOk();
        $this->assertSame($before, AdminAction::count());
    }

    public function test_only_super_admin_reads_the_trail(): void
    {
        $appointment = $this->appointment();
        Sanctum::actingAs($this->staff(User::ROLE_SPECIAL_ADMIN));
        $this->deleteJson("/api/appointments/{$appointment->id}")->assertOk();
        $this->getJson('/api/admin-actions')->assertForbidden();

        Sanctum::actingAs($this->staff(User::ROLE_SUPER_ADMIN));
        $this->getJson("/api/admin-actions?event_id={$this->eventId}&patient_id=8100")
            ->assertOk()
            ->assertJsonPath('actions.0.label', 'حذف موعداً')
            ->assertJsonPath('actions.0.user', 'staff 3');
    }
}
