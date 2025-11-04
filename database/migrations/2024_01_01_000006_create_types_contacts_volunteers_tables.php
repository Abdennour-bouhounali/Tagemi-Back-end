<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('types', function (Blueprint $table) {
            $table->id();
            $table->string('name_en');
            $table->string('name_ar');
            $table->text('description_en')->nullable();
            $table->text('description_ar')->nullable();
            $table->string('image_url');
            $table->json('statistics')->nullable();
            $table->timestamps();
        });

        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('phone', 20);
            $table->text('message');
            $table->string('state');
            $table->string('commun');
            $table->string('type_message');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });

        Schema::create('volunteers', function (Blueprint $table) {
            $table->id();
            $table->string('full_name');
            $table->date('date_of_birth');
            $table->string('gender', 10);
            $table->string('phone_number', 20);
            $table->string('email_address')->unique();
            $table->string('city', 100);
            $table->string('state', 100);
            $table->text('relevant_skills')->nullable();
            $table->text('previous_volunteering_experience')->nullable();
            $table->text('professional_background')->nullable();
            $table->text('areas_of_interest')->nullable();
            $table->text('preferred_types_of_activities')->nullable();
            $table->text('reasons_for_volunteering')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            
            $table->index('email_address', 'idx_email');
            $table->index(['state', 'city'], 'idx_state_city');
            $table->index('created_at', 'idx_created_at');
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('types');
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('volunteers');
        Schema::dropIfExists('personal_access_tokens');
    }
};