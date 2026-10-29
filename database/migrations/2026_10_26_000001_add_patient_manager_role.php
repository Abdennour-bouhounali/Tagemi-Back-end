<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Patient manager (مسؤول القاعة الصغرى): works the specialty queues like a check
 * admin but may only push patients down, never mark them as passed.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('roles')->updateOrInsert(['id' => 8], ['name' => 'PatientManager', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('users')->where('role_id', 8)->update(['role_id' => 2]);
        DB::table('roles')->where('id', 8)->delete();
    }
};
