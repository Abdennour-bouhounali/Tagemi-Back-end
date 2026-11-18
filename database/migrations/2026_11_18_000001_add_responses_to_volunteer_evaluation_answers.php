<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Questions of «تقييم المتطوعين» now have a type, like a form: rating 1..5
// (the default, still saved in `ratings`), open text, yes / no, single choice
// and multiple choice. The non-rating answers go in `responses`:
// {questionId: "text" | "yes" | "no" | optionId | [optionId]}.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('volunteer_evaluation_answers', function (Blueprint $table) {
            $table->json('responses')->nullable()->after('ratings');
        });
    }

    public function down(): void
    {
        Schema::table('volunteer_evaluation_answers', function (Blueprint $table) {
            $table->dropColumn('responses');
        });
    }
};
