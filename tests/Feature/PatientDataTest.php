<?php

namespace Tests\Feature;

use App\Services\QueueService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Booking needs the data-processing consent and records when it was given;
 * the patient can then read their own data back (right of access, law 18-07).
 *   DB_DATABASE=tagemi_security_test php artisan test
 */
class PatientDataTest extends TestCase
{
    use DatabaseTransactions;

    private int $eventId;
    private array $slot;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([1 => 'SuperAdmin', 2 => 'SimpleUser'] as $id => $name) {
            DB::table('roles')->updateOrInsert(['id' => $id], ['name' => $name]);
        }
        DB::table('users')->updateOrInsert(['id' => 1], ['name' => 'admin', 'email' => 'admin1@test.local', 'password' => bcrypt('x'), 'role_id' => 1]);
        $this->eventId = DB::table('events')->insertGetId([
            'name' => 'قافلة', 'date' => QueueService::today(), 'is_active' => 1, 'is_archived' => 0, 'nbr_of_allowed_specialties' => 1,
        ]);
        $specialtyId = DB::table('specialties')->insertGetId(['name' => 'S' . uniqid()]);
        $esId = DB::table('event_specialties')->insertGetId(['event_id' => $this->eventId, 'specialty_id' => $specialtyId]);
        $dayId = DB::table('days')->insertGetId(['event_specialty_id' => $esId, 'day_date' => QueueService::today()]);
        $hourId = DB::table('hours')->insertGetId(['day_id' => $dayId, 'time' => '09:00:00', 'max_allowed' => 10]);
        $this->slot = ['event_specialty_id' => $esId, 'day_id' => $dayId, 'hour_id' => $hourId];
    }

    private function booking(array $extra = []): array
    {
        return array_merge([
            'name' => 'أحمد', 'lastName' => 'بن علي', 'birthday' => '1990-05-05', 'state' => 'Adrar', 'city' => 'Adrar',
            'phone' => '0555 00 01 11', 'sex' => '0', 'diseases' => 'السكري', 'specialties' => [$this->slot],
        ], $extra);
    }

    public function test_booking_requires_consent_and_records_it(): void
    {
        $this->postJson("/api/appointment/{$this->eventId}", $this->booking())->assertStatus(422);

        $this->postJson("/api/appointment/{$this->eventId}", $this->booking(['data_consent' => '1']))->assertCreated();

        $row = DB::table('appointments')->where('event_id', $this->eventId)->first();
        $this->assertNotNull($row->data_consent_at);
        $this->assertSame('', $row->phone2); // second phone is optional
    }

    public function test_patient_reads_own_data_with_the_three_answers_only(): void
    {
        $patientId = $this->postJson("/api/appointment/{$this->eventId}", $this->booking(['data_consent' => '1']))
            ->assertCreated()->json('patient_id');

        $this->postJson('/api/public/my-data', ['patient_id' => $patientId, 'phone' => '+213555000111', 'birthday' => '1990-05-05'])
            ->assertOk()
            ->assertJsonPath('person.full_name', 'أحمد بن علي')
            ->assertJsonPath('person.diseases', 'السكري')
            ->assertJsonCount(1, 'visits');

        $this->postJson('/api/public/my-data', ['patient_id' => $patientId, 'phone' => '0555000111', 'birthday' => '1990-05-06'])
            ->assertNotFound();
        $this->postJson('/api/public/my-data', ['patient_id' => $patientId, 'phone' => '0555999999', 'birthday' => '1990-05-05'])
            ->assertNotFound();
    }
}
