<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Accounts now carry a first name and a family name, both in Arabic; `name`
 * stays their joined form. Existing accounts whose name is already Arabic with
 * at least two words are split here; the others (French, first name only) are
 * left empty, so the app asks them for their names at their next visit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name', 100)->nullable()->after('name');
            $table->string('family_name', 100)->nullable()->after('first_name');
        });

        DB::table('users')->select('id', 'name')->orderBy('id')->each(function ($user) {
            [$first, $family] = User::splitArabicName((string) $user->name);
            if ($first !== null) {
                DB::table('users')->where('id', $user->id)->update(['first_name' => $first, 'family_name' => $family]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['first_name', 'family_name']);
        });
    }
};
