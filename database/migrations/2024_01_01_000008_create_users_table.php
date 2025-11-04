<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->default(2)->constrained('roles')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('event_id')->nullable()->constrained('events')->nullOnDelete()->cascadeOnUpdate();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('phone', 20)->nullable();
            $table->rememberToken();
            $table->timestamps();
            
            $table->index('event_id', 'idx_users_event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};