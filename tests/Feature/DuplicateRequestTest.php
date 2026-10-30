<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\QueueService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Double clicks / slow-network retries are processed once (PreventDuplicateRequests).
 *   DB_DATABASE=tagemi_security_test php artisan test
 */
class DuplicateRequestTest extends TestCase
{
    use DatabaseTransactions;

    private int $eventId;
    private array $slot;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.duplicate_request_window' => 10]);

        // Every role: users default to role 2 (empty database in CI)
        foreach ([1 => 'SuperAdmin', 2 => 'SimpleUser', 3 => 'SpecialAdmin', 4 => 'CheckAdmin', 5 => 'RecipientAdmin', 6 => 'Doctor', 7 => 'ContentAdmin', 8 => 'PatientManager'] as $id => $name) {
            DB::table('roles')->updateOrInsert(['id' => $id], ['name' => $name]);
        }
        // Booking code stores user_id = 1 (the super admin in production)
        DB::table('users')->updateOrInsert(['id' => 1], ['name' => 'admin', 'email' => 'admin1@test.local', 'password' => bcrypt('x'), 'role_id' => 1]);
        $this->eventId = DB::table('events')->insertGetId([
            'name' => 'Dup', 'date' => QueueService::today(), 'is_active' => 1, 'is_archived' => 0, 'nbr_of_allowed_specialties' => 1,
        ]);
        $specialtyId = DB::table('specialties')->insertGetId(['name' => 'S' . uniqid()]);
        $esId = DB::table('event_specialties')->insertGetId(['event_id' => $this->eventId, 'specialty_id' => $specialtyId]);
        $dayId = DB::table('days')->insertGetId(['event_specialty_id' => $esId, 'day_date' => QueueService::today()]);
        $hourId = DB::table('hours')->insertGetId(['day_id' => $dayId, 'time' => '09:00:00', 'max_allowed' => 10]);
        $this->slot = ['event_specialty_id' => $esId, 'day_id' => $dayId, 'hour_id' => $hourId];
    }

    private function booking(string $name): array
    {
        return [
            'name' => $name, 'lastName' => 'بن علي', 'birthday' => '1990-05-05', 'state' => 'Adrar', 'city' => 'Adrar',
            'phone' => '0555000111', 'phone2' => '0555000222', 'sex' => '0', 'data_consent' => '1', 'specialties' => [$this->slot],
        ];
    }

    public function test_double_clicked_booking_is_booked_once_with_the_same_answer(): void
    {
        $first = $this->postJson("/api/appointment/{$this->eventId}", $this->booking('أحمد'))->assertCreated();
        $second = $this->postJson("/api/appointment/{$this->eventId}", $this->booking('أحمد'))->assertCreated();

        $second->assertHeader('X-Duplicate-Request', 'replayed');
        $this->assertSame($first->json('patient_id'), $second->json('patient_id'));
        $this->assertSame(1, DB::table('appointments')->where('event_id', $this->eventId)->count());
    }

    public function test_same_idempotency_key_replays_the_first_answer(): void
    {
        $headers = ['Idempotency-Key' => 'form-' . uniqid()];
        $first = $this->postJson("/api/appointment/{$this->eventId}", $this->booking('أحمد'), $headers)->assertCreated();
        // A retry of the same submission, even if a field changed meanwhile
        $this->postJson("/api/appointment/{$this->eventId}", $this->booking('محمد'), $headers)
            ->assertCreated()->assertJsonPath('patient_id', $first->json('patient_id'));

        $this->assertSame(1, DB::table('appointments')->where('event_id', $this->eventId)->count());
    }

    public function test_different_requests_are_all_processed(): void
    {
        $this->postJson("/api/appointment/{$this->eventId}", $this->booking('أحمد'))->assertCreated();
        $this->postJson("/api/appointment/{$this->eventId}", $this->booking('محمد'))->assertCreated();

        $this->assertSame(2, DB::table('appointments')->where('event_id', $this->eventId)->count());
    }

    public function test_toggles_are_never_merged(): void
    {
        $admin = User::create(['name' => 'a', 'email' => uniqid('a', true) . '@test.local', 'password' => 'password123']);
        $admin->forceFill(['role_id' => 1])->save();
        Sanctum::actingAs($admin);
        $esId = $this->slot['event_specialty_id'];

        // Open then close right away: both must happen (same request, opposite effects)
        $this->patchJson("/api/event-specialties/{$esId}/toggle-open")->assertOk()->assertJsonPath('is_open', true);
        $this->patchJson("/api/event-specialties/{$esId}/toggle-open")->assertOk()->assertJsonPath('is_open', false)
            ->assertHeaderMissing('X-Duplicate-Request');
    }

    public function test_admin_double_click_creates_one_doctor(): void
    {
        $admin = User::create(['name' => 'a', 'email' => uniqid('a', true) . '@test.local', 'password' => 'password123']);
        $admin->forceFill(['role_id' => 1])->save();
        Sanctum::actingAs($admin);

        $payload = [
            'first_name' => 'أمين', 'family_name' => 'بن علي', 'sex' => 'male', 'phone' => '07' . random_int(10000000, 99999999),
            'wilaya_code' => '16', 'specialty_id' => DB::table('specialties')->value('id'), 'sector' => 'public',
        ];
        $first = $this->postJson('/api/doctors', $payload)->assertCreated();
        $this->postJson('/api/doctors', $payload)->assertCreated()->assertJsonPath('doctor.id', $first->json('doctor.id'));

        $this->assertSame(1, DB::table('doctors')->where('phone', $payload['phone'])->count());
    }
}
