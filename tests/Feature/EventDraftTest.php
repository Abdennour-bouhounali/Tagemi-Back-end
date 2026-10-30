<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Drafts of the new-event form: saved per super admin, removed once the event exists. */
class EventDraftTest extends TestCase
{
    use DatabaseTransactions;

    private function superAdmin(): User
    {
        foreach ([1 => 'SuperAdmin', 2 => 'SimpleUser', 3 => 'SpecialAdmin', 4 => 'CheckAdmin', 5 => 'RecipientAdmin', 6 => 'Doctor', 7 => 'ContentAdmin', 8 => 'PatientManager'] as $id => $name) {
            DB::table('roles')->updateOrInsert(['id' => $id], ['name' => $name]);
        }
        DB::table('users')->updateOrInsert(['id' => 1], ['name' => 'admin', 'email' => 'admin1@test.local', 'password' => bcrypt('x'), 'role_id' => 1]);
        $user = User::create(['name' => 'a', 'email' => uniqid('a', true) . '@test.local', 'password' => 'password123']);
        $user->forceFill(['role_id' => 1])->save();

        return $user;
    }

    public function test_drafts_are_saved_listed_and_deleted_by_their_owner_only(): void
    {
        $owner = $this->superAdmin();
        $other = $this->superAdmin();
        Sanctum::actingAs($owner);

        $id = $this->postJson('/api/event-drafts', ['name' => 'قافلة', 'data' => ['form' => ['name' => 'قافلة'], 'step' => 0]])
            ->assertCreated()->json('draft.id');
        $this->putJson("/api/event-drafts/{$id}", ['name' => 'قافلة 2', 'data' => ['form' => ['name' => 'قافلة 2'], 'step' => 1]])->assertOk();

        $this->getJson('/api/event-drafts')->assertOk()->assertJsonPath('drafts.0.name', 'قافلة 2');
        $this->getJson("/api/event-drafts/{$id}")->assertOk()->assertJsonPath('draft.data.step', 1);

        Sanctum::actingAs($other);
        $this->getJson("/api/event-drafts/{$id}")->assertNotFound();
        $this->deleteJson("/api/event-drafts/{$id}")->assertNotFound();
        $this->getJson('/api/event-drafts')->assertOk()->assertJsonCount(0, 'drafts');

        Sanctum::actingAs($owner);
        $this->deleteJson("/api/event-drafts/{$id}")->assertOk();
        $this->assertDatabaseMissing('event_drafts', ['id' => $id]);
    }

    public function test_creating_the_event_removes_its_draft(): void
    {
        Sanctum::actingAs($this->superAdmin());
        $specialtyId = DB::table('specialties')->insertGetId(['name' => uniqid('s')]);
        $draftId = $this->postJson('/api/event-drafts', ['data' => ['step' => 2]])->json('draft.id');

        $this->postJson('/api/events', [
            'name' => 'قافلة', 'date' => '2030-03-10', 'draft_id' => $draftId,
            'specialties' => [['id' => $specialtyId, 'days' => [['day_date' => '2030-03-10', 'number_per_hour' => 6, 'hours' => [['time' => '08:00:00']]]]]],
        ])->assertCreated();

        $this->assertDatabaseMissing('event_drafts', ['id' => $draftId]);
    }

    public function test_only_super_admins_have_drafts(): void
    {
        $this->superAdmin();
        $user = User::create(['name' => 'r', 'email' => uniqid('r', true) . '@test.local', 'password' => 'password123']);
        $user->forceFill(['role_id' => 5])->save();
        Sanctum::actingAs($user);

        $this->getJson('/api/event-drafts')->assertForbidden();
    }
}
