<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\QueueService;
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
        $this->assertSame(100, DB::table('appointments')->where('event_id', $id)->count());
        $this->assertSame(100, DB::table('appointments')->where('event_id', $id)->where('status', 'Pending')->count());
        $this->assertSame(8, DB::table('days')->whereIn('event_specialty_id', DB::table('event_specialties')->where('event_id', $id)->pluck('id'))->where('day_date', QueueService::today())->count());
        $this->assertSame(100, (int) DB::table('hours')->join('days', 'days.id', '=', 'hours.day_id')->join('event_specialties', 'event_specialties.id', '=', 'days.event_specialty_id')->where('event_id', $id)->sum('counter'));
        $this->assertSame(0, DB::table('hours')->join('days', 'days.id', '=', 'hours.day_id')->join('event_specialties', 'event_specialties.id', '=', 'days.event_specialty_id')->where('event_id', $id)->whereColumn('counter', '>', 'max_allowed')->count());
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
        $this->assertSame(100, DB::table('appointments')->where('event_id', $id)->count());
        $this->assertSame(100, DB::table('appointments')->where('event_id', $id)->where('status', 'Pending')->count());
        $this->assertSame(0, DB::table('event_specialties')->where('event_id', $id)->whereNotNull('opened_on')->count());
        $this->assertSame(8, DB::table('event_specialties')->where('event_id', $id)->count());
    }

    public function test_delete_removes_the_event_and_everything_under_it(): void
    {
        $id = $this->start();
        $this->deleteJson("/api/events/{$id}/training")->assertOk();

        $this->assertNull(DB::table('events')->find($id));
        $this->assertSame(0, DB::table('appointments')->where('event_id', $id)->count());
        $this->assertSame(0, DB::table('event_specialties')->where('event_id', $id)->count());
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
