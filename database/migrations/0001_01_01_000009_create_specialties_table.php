<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('specialties', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->integer('duration')->default(10);
            $table->integer('Max_Number')->default(100);
            $table->time('specialty_time')->default('08:00:00');
            $table->integer('Addition_Capacitif')->default(0);
            $table->string('Flag',20)->default('Open');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('specialties');
    }
};
