<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backfill_jobs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->unsignedBigInteger('user_wallet_id');
            $table->foreign(['user_wallet_id', 'user_id'])->references(['id', 'user_id'])->on('user_wallets');
            $table->foreignId('cursor_id')->nullable()->unique()->constrained('scan_cursors');
            $table->unsignedBigInteger('from_block');
            $table->unsignedBigInteger('to_block');
            $table->boolean('notify')->default(false);
            $table->string('status', 20)->default('pending');
            $table->timestamp('available_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamps();
            $table->unique(['user_wallet_id', 'from_block', 'to_block', 'notify'], 'backfill_identity_unique');
            $table->index(['status', 'available_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backfill_jobs');
    }
};
