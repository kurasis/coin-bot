<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_subscriptions', function (Blueprint $table): void {
            $table->unsignedBigInteger('notification_start_block')->default(0);
        });
        DB::statement('UPDATE wallet_subscriptions SET notification_start_block = (SELECT start_block FROM user_wallets WHERE user_wallets.id = wallet_subscriptions.user_wallet_id)');
        Schema::table('outbox_authorizations', function (Blueprint $table): void {
            $table->boolean('historical_override')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('outbox_authorizations', fn (Blueprint $table) => $table->dropColumn('historical_override'));
        Schema::table('wallet_subscriptions', fn (Blueprint $table) => $table->dropColumn('notification_start_block'));
    }
};
