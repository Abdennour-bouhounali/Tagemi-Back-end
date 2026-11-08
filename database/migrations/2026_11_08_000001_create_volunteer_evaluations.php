<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Volunteer debrief after an event («تقييم المتطوعين»). One evaluation per
// event: its roles, axes and questions live as JSON on the evaluation so the
// super admin can edit them freely; question ids never change, so answers
// stay attached. Volunteers enter anonymously from a QR code: a participant
// is a random token kept on their phone, plus the role they picked.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('volunteer_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->unique()->constrained('events')->cascadeOnDelete();
            $table->string('title');
            $table->json('roles');            // [{id, name}]
            $table->json('axes');             // [{id, title, roles: [roleId], qs: [{id, g, t}]}]
            $table->json('recommendations')->nullable(); // {axisId: text}
            $table->boolean('is_open')->default(true);   // accepts answers
            $table->enum('phase', ['eval', 'results'])->default('eval');
            $table->unsignedBigInteger('ends_at')->default(0); // countdown end, epoch ms; 0 = not started
            $table->unsignedSmallInteger('duration_min')->default(30);
            $table->timestamps();
        });

        Schema::create('volunteer_evaluation_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('volunteer_evaluation_id');
            $table->char('token_hash', 64)->unique('ve_participants_token_unique');
            $table->string('role_id', 40)->nullable();
            $table->timestamps();

            // Default names pass MySQL's 64-character limit
            $table->foreign('volunteer_evaluation_id', 've_participants_evaluation_fk')
                ->references('id')->on('volunteer_evaluations')->cascadeOnDelete();
        });

        Schema::create('volunteer_evaluation_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('volunteer_evaluation_id');
            $table->foreignId('participant_id');
            $table->string('axis_id', 40);
            $table->enum('status', ['done', 'skipped']);
            $table->unsignedTinyInteger('overall')->nullable(); // 0 = don't know, 1..5
            $table->json('ratings')->nullable();   // {questionId: 0..5}
            $table->json('positives')->nullable(); // [{text, reason, recommendation}]
            $table->json('negatives')->nullable();
            $table->timestamps();

            $table->unique(['participant_id', 'axis_id'], 've_answers_participant_axis_unique');
            $table->index(['volunteer_evaluation_id', 'updated_at'], 've_answers_evaluation_updated_index');
            $table->foreign('volunteer_evaluation_id', 've_answers_evaluation_fk')
                ->references('id')->on('volunteer_evaluations')->cascadeOnDelete();
            $table->foreign('participant_id', 've_answers_participant_fk')
                ->references('id')->on('volunteer_evaluation_participants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('volunteer_evaluation_answers');
        Schema::dropIfExists('volunteer_evaluation_participants');
        Schema::dropIfExists('volunteer_evaluations');
    }
};
