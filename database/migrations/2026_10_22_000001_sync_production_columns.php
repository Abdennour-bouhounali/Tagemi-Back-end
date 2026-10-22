<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Columns that were added to the production database by hand. Each one is
// guarded, so this is a no-op on production and only fixes fresh databases.
return new class extends Migration
{
    public function up(): void
    {
        foreach (['appointments', 'appointments_archive'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (!Schema::hasColumn($table, 'is_reminded')) {
                    $t->boolean('is_reminded')->default(false);
                }
                if (!Schema::hasColumn($table, 'reminded_at')) {
                    $t->timestamp('reminded_at')->nullable();
                }
                if (!Schema::hasColumn($table, 'is_evaluated')) {
                    $t->boolean('is_evaluated')->default(false);
                }
                if (!Schema::hasColumn($table, 'evaluated_at')) {
                    $t->timestamp('evaluated_at')->nullable();
                }
            });
        }

        Schema::table('events', function (Blueprint $t) {
            if (!Schema::hasColumn('events', 'nbr_of_allowed_specialties')) {
                $t->integer('nbr_of_allowed_specialties')->default(1);
            }
            if (!Schema::hasColumn('events', 'rules_ids')) {
                $t->json('rules_ids')->nullable();
            }
        });

        Schema::table('event_specialties', function (Blueprint $t) {
            if (!Schema::hasColumn('event_specialties', 'description')) {
                $t->text('description')->nullable();
            }
        });
    }

    public function down(): void
    {
        // Production depends on these columns; never drop them.
    }
};
