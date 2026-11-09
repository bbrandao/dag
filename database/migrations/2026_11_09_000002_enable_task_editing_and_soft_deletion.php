<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_templates', fn (Blueprint $table) => $table->softDeletesTz());
        Schema::table('task_assignments', fn (Blueprint $table) => $table->boolean('active')->default(true));
        Schema::table('task_occurrences', function (Blueprint $table): void {
            $table->dropUnique(['task_id', 'child_id', 'period_start']);
            $table->unique(['task_id', 'child_id', 'frequency', 'period_start'], 'task_occurrences_period_unique');
        });
    }

    public function down(): void
    {
        Schema::table('task_occurrences', function (Blueprint $table): void {
            $table->unique(['task_id', 'child_id', 'period_start']);
            $table->dropUnique('task_occurrences_period_unique');
        });
        Schema::table('task_assignments', fn (Blueprint $table) => $table->dropColumn('active'));
        Schema::table('task_templates', fn (Blueprint $table) => $table->dropSoftDeletes());
    }
};
