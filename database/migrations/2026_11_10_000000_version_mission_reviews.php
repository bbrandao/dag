<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mission_submissions', fn (Blueprint $table) => $table->unsignedInteger('review_version')->default(0));
        Schema::table('mission_notices', function (Blueprint $table): void {
            $table->unsignedInteger('review_version')->default(0);
            $table->dropUnique('mission_notices_recipient_id_submission_id_kind_unique');
            $table->unique(['recipient_id', 'submission_id', 'kind', 'review_version'], 'mission_notices_version_unique');
        });
        Schema::create('mission_review_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('submission_id')->constrained('mission_submissions');
            $table->unsignedInteger('review_version');
            $table->string('decision', 20);
            $table->string('feedback', 255)->nullable();
            $table->foreignUuid('reviewer_id')->constrained('users');
            $table->timestampTz('reviewed_at');
            $table->unique(['submission_id', 'review_version']);
        });
    }

    public function down(): void
    {
        // Restoring the previous constraint must succeed before removing any revision data.
        Schema::table('mission_notices', fn (Blueprint $table) => $table->unique(['recipient_id', 'submission_id', 'kind']));
        Schema::dropIfExists('mission_review_changes');
        Schema::table('mission_notices', function (Blueprint $table): void {
            $table->dropUnique('mission_notices_version_unique');
            $table->dropColumn('review_version');
        });
        Schema::table('mission_submissions', fn (Blueprint $table) => $table->dropColumn('review_version'));
    }
};
