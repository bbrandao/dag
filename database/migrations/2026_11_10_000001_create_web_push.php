<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('session_version');
            $table->string('endpoint_hash', 64)->unique();
            $table->text('subscription');
            $table->index('user_id');
        });

        Schema::create('push_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subscription_id')->constrained('push_subscriptions')->cascadeOnDelete();
            $table->foreignUuid('recipient_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('occurrence_id')->constrained('task_occurrences')->cascadeOnDelete();
            $table->foreignUuid('submission_id')->nullable()->constrained('mission_submissions')->cascadeOnDelete();
            $table->string('event_key', 64);
            $table->timestampTz('deadline')->nullable();
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('available_at');
            $table->string('status', 12)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unique(['subscription_id', 'event_key']);
            $table->index(['status', 'available_at']);
            $table->index('submission_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_deliveries');
        Schema::dropIfExists('push_subscriptions');
    }
};
