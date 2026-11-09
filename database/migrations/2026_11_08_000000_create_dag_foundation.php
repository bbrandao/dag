<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('families', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            $table->string('timezone', 64);
            $table->timestampsTz();
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('family_id')->constrained();
            $table->string('name', 120);
            $table->string('role', 16);
            $table->string('relationship', 24)->nullable();
            $table->text('phone');
            $table->string('phone_hash', 64)->unique();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('session_version')->default(1);
            $table->timestampsTz();
            $table->unique(['family_id', 'id']);
        });

        Schema::create('children', function (Blueprint $table): void {
            $table->uuid('user_id')->primary();
            $table->uuid('family_id');
            $table->text('birth_date');
            $table->foreign(['family_id', 'user_id'])->references(['family_id', 'id'])->on('users');
        });

        Schema::create('guardian_child_links', function (Blueprint $table): void {
            $table->uuid('family_id');
            $table->uuid('guardian_id');
            $table->uuid('child_id');
            $table->timestampTz('authorized_at');
            $table->boolean('active')->default(true);
            $table->primary(['guardian_id', 'child_id']);
            $table->foreign(['family_id', 'guardian_id'])->references(['family_id', 'id'])->on('users');
            $table->foreign(['family_id', 'child_id'])->references(['family_id', 'id'])->on('users');
            $table->foreign('child_id')->references('user_id')->on('children');
        });

        Schema::create('audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('family_id')->constrained();
            $table->uuid('actor_id')->nullable();
            $table->uuid('subject_id');
            $table->string('event', 64);
            $table->timestampTz('recorded_at');
        });

        Schema::create('otp_challenges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('digest', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('consumed_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->uuid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        Schema::create('cache', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->bigInteger('expiration')->index();
        });

        Schema::create('cache_locks', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->string('owner');
            $table->bigInteger('expiration')->index();
        });
    }

    public function down(): void
    {
        foreach (['cache_locks', 'cache', 'sessions', 'otp_challenges', 'audit_events', 'guardian_child_links', 'children', 'users', 'families'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
