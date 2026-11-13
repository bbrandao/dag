<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversion_rates', function (Blueprint $table): void {
            $table->timestampTz('recorded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('conversion_rates', function (Blueprint $table): void {
            $table->dropColumn('recorded_at');
        });
    }
};
