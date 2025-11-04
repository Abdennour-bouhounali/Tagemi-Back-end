<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->text('name');
            $table->date('date');
            $table->string('state')->nullable();
            $table->string('city')->nullable();
            $table->string('type', 20)->default('TAWAT');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('check_admin_id')->nullable();
            $table->foreignId('special_admin_id')->nullable();
            $table->foreignId('recipient_admin_id')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->boolean('is_tawat')->default(true);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};