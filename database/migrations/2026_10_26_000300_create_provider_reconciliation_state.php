<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_health', function (Blueprint $table): void {
            $table->id();
            $table->string('chain', 16);
            $table->string('provider', 64);
            $table->unsignedInteger('failure_count')->default(0);
            $table->timestamp('opens_until')->nullable();
            $table->unsignedInteger('last_http_status')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamps();
            $table->unique(['chain', 'provider']);
        });
        Schema::create('reconciliation_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('range_id')->constrained('scan_ranges');
            $table->string('provider', 64);
            $table->string('status', 24);
            $table->string('observed_digest', 64)->nullable();
            $table->unsignedInteger('repaired_events')->default(0);
            $table->unsignedInteger('index_omissions')->default(0);
            $table->string('failure_code', 64)->nullable();
            $table->timestamp('last_checked_at');
            $table->timestamps();
            $table->unique(['range_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_checks');
        Schema::dropIfExists('provider_health');
    }
};
