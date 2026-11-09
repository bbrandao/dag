<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->boolean('whatsapp_notices')->default(false));

        Schema::create('mission_submissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('occurrence_id')->constrained('task_occurrences');
            $table->uuid('request_id');
            $table->string('comment', 255)->nullable();
            $table->string('photo_path')->nullable();
            $table->timestampTz('submitted_at');
            $table->foreignUuid('reviewer_id')->nullable()->constrained('users');
            $table->string('decision', 20)->nullable();
            $table->string('feedback', 255)->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->unique(['occurrence_id', 'request_id']);
            $table->index(['occurrence_id', 'submitted_at']);
        });

        Schema::create('mission_reopenings', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('occurrence_id')->constrained('task_occurrences');
            $table->foreignUuid('guardian_id')->constrained('users');
            $table->timestampTz('previous_due_at');
            $table->timestampTz('new_due_at');
            $table->string('reason', 255);
            $table->timestampTz('reopened_at');
        });

        Schema::create('point_credits', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('occurrence_id')->unique()->constrained('task_occurrences');
            $table->unsignedInteger('points');
            $table->timestampTz('approved_at');
        });

        Schema::create('mission_notices', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('recipient_id')->constrained('users');
            $table->foreignUuid('submission_id')->constrained('mission_submissions');
            $table->string('kind', 20);
            $table->string('status', 16)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestampTz('available_at');
            $table->unique(['recipient_id', 'submission_id', 'kind']);
            $table->index(['status', 'available_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mission_notices');
        Schema::dropIfExists('point_credits');
        Schema::dropIfExists('mission_reopenings');
        Schema::dropIfExists('mission_submissions');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('whatsapp_notices'));
    }
};
