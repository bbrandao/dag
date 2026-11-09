<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('family_id')->constrained();
            $table->string('title', 120);
            $table->text('criteria');
            $table->unsignedInteger('points');
            $table->string('frequency', 12);
            $table->json('schedule');
            $table->date('starts_on');
            $table->time('weekday_time');
            $table->time('weekend_time');
            $table->string('photo_mode', 12);
            $table->timestampsTz();
            $table->unique(['family_id', 'id']);
        });

        Schema::create('task_assignments', function (Blueprint $table): void {
            $table->uuid('family_id');
            $table->uuid('task_id');
            $table->uuid('child_id');
            $table->primary(['task_id', 'child_id']);
            $table->index(['family_id', 'child_id']);
            $table->foreign(['family_id', 'task_id'])->references(['family_id', 'id'])->on('task_templates');
            $table->foreign(['family_id', 'child_id'])->references(['family_id', 'id'])->on('users');
            $table->foreign('child_id')->references('user_id')->on('children');
        });

        Schema::create('task_occurrences', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('family_id');
            $table->uuid('task_id');
            $table->uuid('child_id');
            $table->date('period_start');
            $table->date('due_on');
            $table->timestampTz('opens_at');
            $table->timestampTz('due_at');
            $table->string('title', 120);
            $table->text('criteria');
            $table->unsignedInteger('points');
            $table->string('frequency', 12);
            $table->string('photo_mode', 12);
            $table->string('status', 16)->default('pending');
            $table->unique(['task_id', 'child_id', 'period_start']);
            $table->index(['family_id', 'child_id', 'status', 'due_at'], 'task_occurrences_pending_index');
            $table->foreign(['family_id', 'task_id'])->references(['family_id', 'id'])->on('task_templates');
            $table->foreign(['family_id', 'child_id'])->references(['family_id', 'id'])->on('users');
            $table->foreign(['task_id', 'child_id'])->references(['task_id', 'child_id'])->on('task_assignments');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_occurrences');
        Schema::dropIfExists('task_assignments');
        Schema::dropIfExists('task_templates');
    }
};
