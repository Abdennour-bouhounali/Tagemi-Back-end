<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Organizer (مسؤول التنظيم): sees every screen of their event (queues, patients,
 * doctors, alarms) to act on the ground, but can change nothing in the system.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('roles')->updateOrInsert(['id' => 9], ['name' => 'Organizer', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('users')->where('role_id', 9)->update(['role_id' => 2]);
        DB::table('roles')->where('id', 9)->delete();
    }
};
