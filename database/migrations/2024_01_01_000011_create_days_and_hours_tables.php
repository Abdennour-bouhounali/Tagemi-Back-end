<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_specialty_id')->constrained('event_specialties')->cascadeOnDelete()->cascadeOnUpdate();
            $table->date('day_date');
            $table->integer('number_per_hour')->default(6);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            
            $table->unique(['event_specialty_id', 'day_date'], 'ux_days_event_specialty_day');
        });

        Schema::create('hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('day_id')->constrained('days')->cascadeOnDelete()->cascadeOnUpdate();
            $table->time('time');
            $table->integer('max_allowed')->nullable();
            $table->integer('counter')->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            
            $table->unique(['day_id', 'time'], 'ux_hours_day_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hours');
        Schema::dropIfExists('days');
    }
};