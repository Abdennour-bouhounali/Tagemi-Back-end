<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Brings fresh databases in line with production, where these columns were
// added by hand. Guarded so it is a no-op on the production database.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluations', function (Blueprint $table) {
            if (!Schema::hasColumn('evaluations', 'specialties')) {
                $table->json('specialties')->nullable()->after('specialty');
            }
            if (!Schema::hasColumn('evaluations', 'specialty_other')) {
                $table->string('specialty_other')->nullable()->after('specialties');
            }
        });
    }

    public function down(): void
    {
        // Columns exist in production independently of this migration; keep them.
    }
};
