<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_incidents', function (Blueprint $table): void {
            $table->id();
            $table->string('chain', 16)->unique();
            $table->string('state', 16)->default('healthy');
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->unsignedBigInteger('generation')->default(0);
            $table->string('error_category', 32)->nullable();
            $table->timestamp('first_failure_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->timestamp('last_counted_failure_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('recovered_at')->nullable();
            $table->timestamps();
        });
        Schema::create('monitoring_alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('incident_id')->constrained('monitoring_incidents');
            $table->unsignedBigInteger('generation');
            $table->string('kind', 16);
            $table->foreignId('user_id')->constrained();
            $table->string('chat_id', 32);
            $table->text('text');
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            // Set immediately before HTTP; cleared only on a definite rejection.
            $table->boolean('possibly_delivered')->default(false);
            $table->timestamp('available_at')->nullable();
            $table->string('lease_token', 64)->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->string('telegram_message_id', 32)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamps();
            $table->unique(['incident_id', 'generation', 'kind'], 'monitoring_alert_incident_kind_unique');
            $table->index(['status', 'available_at', 'lease_expires_at'], 'monitoring_alert_ready_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_alerts');
        Schema::dropIfExists('monitoring_incidents');
    }
};
