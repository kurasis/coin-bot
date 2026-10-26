<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_dispatch_state', function (Blueprint $table): void {
            $table->string('name', 64)->primary();
            $table->unsignedBigInteger('next_allowed_ms')->default(0);
        });
        Schema::create('telegram_replies', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('update_id')->unique();
            $table->string('chat_id', 32);
            $table->foreignId('user_id')->nullable()->constrained();
            $table->text('text');
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->string('lease_token', 64)->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->string('telegram_message_id', 32)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamps();
            $table->index(['status', 'available_at', 'lease_expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_replies');
        Schema::dropIfExists('telegram_dispatch_state');
    }
};
