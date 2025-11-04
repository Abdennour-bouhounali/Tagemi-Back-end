<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('specialties', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });

        Schema::create('doctors', function (Blueprint $table) {
            $table->id();
            $table->string('full_name');
            $table->string('email')->nullable();
            $table->string('phone', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });

        Schema::create('static_contents', function (Blueprint $table) {
            $table->id();
            $table->string('key');
            $table->text('value');
            $table->timestamps();
        });

        Schema::create('rules', function (Blueprint $table) {
            $table->id();
            $table->string('rule');
            $table->timestamps();
        });

        Schema::create('parameters', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_day_visits')->default(false);
            $table->boolean('is_aut_displayed')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('specialties');
        Schema::dropIfExists('doctors');
        Schema::dropIfExists('static_contents');
        Schema::dropIfExists('rules');
        Schema::dropIfExists('parameters');
    }
};