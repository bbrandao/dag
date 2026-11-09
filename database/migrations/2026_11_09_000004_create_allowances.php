<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversion_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('family_id')->constrained();
            $table->foreignUuid('guardian_id')->constrained('users');
            $table->unsignedInteger('cents_per_point');
            $table->timestampTz('effective_at');
            $table->index(['family_id', 'effective_at']);
        });

        Schema::table('point_credits', function (Blueprint $table): void {
            $table->foreignId('conversion_rate_id')->nullable()->constrained('conversion_rates');
            $table->unsignedBigInteger('amount_cents')->nullable();
            $table->index('approved_at');
        });

        Schema::create('payouts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('child_id')->constrained('users');
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->uuid('request_id')->nullable();
            $table->date('competence')->nullable();
            $table->unsignedBigInteger('points');
            $table->unsignedBigInteger('amount_cents');
            $table->timestampTz('created_at');
            $table->timestampTz('paid_at')->nullable();
            $table->foreignUuid('paid_by')->nullable()->constrained('users');
            $table->unique(['child_id', 'request_id']);
            $table->index(['child_id', 'created_at']);
        });

        Schema::create('payout_items', function (Blueprint $table): void {
            $table->foreignId('credit_id')->primary()->constrained('point_credits');
            $table->foreignUuid('payout_id')->constrained('payouts');
        });

        Schema::create('settlement_runs', function (Blueprint $table): void {
            $table->foreignUuid('child_id')->constrained('users');
            $table->date('competence');
            $table->timestampTz('cutoff_at');
            $table->foreignUuid('payout_id')->nullable()->constrained('payouts');
            $table->primary(['child_id', 'competence']);
        });

        Schema::create('inbox_reads', function (Blueprint $table): void {
            $table->foreignUuid('user_id')->constrained();
            $table->foreignUuid('submission_id')->constrained('mission_submissions');
            $table->primary(['user_id', 'submission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_reads');
        Schema::dropIfExists('settlement_runs');
        Schema::dropIfExists('payout_items');
        Schema::dropIfExists('payouts');
        Schema::table('point_credits', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('conversion_rate_id');
            $table->dropIndex(['approved_at']);
            $table->dropColumn('amount_cents');
        });
        Schema::dropIfExists('conversion_rates');
    }
};
