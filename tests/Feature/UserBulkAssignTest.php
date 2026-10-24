<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * POST /api/users/bulk-assign. Run against a migrated, empty database:
 *   DB_DATABASE=tagemi_security_test php artisan test
 */
class UserBulkAssignTest extends TestCase
{
    use DatabaseTransactions;

    private int $eventId;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([1 => 'SuperAdmin', 2 => 'SimpleUser', 3 => 'SpecialAdmin', 4 => 'CheckAdmin', 5 => 'RecipientAdmin', 6 => 'Doctor', 7 => 'ContentAdmin'] as $id => $name) {
            DB::table('roles')->updateOrInsert(['id' => $id], ['name' => $name]);
        }
        $this->eventId = DB::table('events')->insertGetId(['name' => 'Bulk event', 'date' => now()->toDateString(), 'is_active' => 1, 'is_archived' => 0]);
    }

    private function user(int $role, ?int $eventId = null): User
    {
        $user = User::create(['name' => 'u', 'email' => uniqid('u', true) . '@test.local', 'password' => 'password123']);
        $user->forceFill(['role_id' => $role, 'event_id' => $eventId])->save();

        return $user;
    }

    public function test_only_super_admin_can_bulk_assign(): void
    {
        $target = $this->user(User::ROLE_SIMPLE_USER);
        Sanctum::actingAs($this->user(User::ROLE_CHECK_ADMIN, $this->eventId));

        $this->postJson('/api/users/bulk-assign', ['user_ids' => [$target->id], 'role_id' => 5])->assertForbidden();
    }

    public function test_sets_event_and_keeps_roles(): void
    {
        Sanctum::actingAs($this->user(User::ROLE_SUPER_ADMIN));
        $a = $this->user(User::ROLE_CHECK_ADMIN);
        $b = $this->user(User::ROLE_DOCTOR);

        $this->postJson('/api/users/bulk-assign', ['user_ids' => [$a->id, $b->id], 'event_id' => $this->eventId])
            ->assertOk()->assertJson(['updated' => 2]);

        $this->assertSame([$this->eventId, User::ROLE_CHECK_ADMIN], [$a->fresh()->event_id, $a->fresh()->role_id]);
        $this->assertSame([$this->eventId, User::ROLE_DOCTOR], [$b->fresh()->event_id, $b->fresh()->role_id]);
    }

    public function test_sets_role_keeps_event_and_skips_super_admin(): void
    {
        $admin = $this->user(User::ROLE_SUPER_ADMIN);
        Sanctum::actingAs($admin);
        $a = $this->user(User::ROLE_SIMPLE_USER, $this->eventId);

        $this->postJson('/api/users/bulk-assign', ['user_ids' => [$a->id, $admin->id], 'role_id' => User::ROLE_RECIPIENT_ADMIN])
            ->assertOk()->assertJson(['updated' => 1, 'skipped' => 1]);

        $this->assertSame([$this->eventId, User::ROLE_RECIPIENT_ADMIN], [$a->fresh()->event_id, $a->fresh()->role_id]);
        $this->assertSame(User::ROLE_SUPER_ADMIN, $admin->fresh()->role_id);
    }

    public function test_null_event_unassigns(): void
    {
        Sanctum::actingAs($this->user(User::ROLE_SUPER_ADMIN));
        $a = $this->user(User::ROLE_DOCTOR, $this->eventId);

        $this->postJson('/api/users/bulk-assign', ['user_ids' => [$a->id], 'event_id' => null])->assertOk();

        $this->assertNull($a->fresh()->event_id);
    }

    public function test_rejects_super_admin_role_and_empty_change(): void
    {
        Sanctum::actingAs($this->user(User::ROLE_SUPER_ADMIN));
        $a = $this->user(User::ROLE_SIMPLE_USER);

        $this->postJson('/api/users/bulk-assign', ['user_ids' => [$a->id], 'role_id' => User::ROLE_SUPER_ADMIN])->assertStatus(422);
        $this->postJson('/api/users/bulk-assign', ['user_ids' => [$a->id]])->assertStatus(422);
        $this->assertSame(User::ROLE_SIMPLE_USER, $a->fresh()->role_id);
    }
}
