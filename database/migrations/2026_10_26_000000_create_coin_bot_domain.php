<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('telegram_user_id', 32)->unique();
            $table->string('state', 20)->default('active');
            $table->timestamps();
        });

        Schema::create('access_keys', function (Blueprint $table): void {
            $table->id();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('grant_expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('redeemed_by_user_id')->nullable()->constrained('users');
            $table->timestamp('redeemed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('access_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('access_key_id')->unique()->constrained();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['id', 'user_id']);
            $table->index(['user_id', 'revoked_at', 'expires_at']);
        });

        Schema::create('wallets', function (Blueprint $table): void {
            $table->id();
            $table->string('chain', 16);
            $table->string('address', 64);
            $table->timestamps();
            $table->unique(['chain', 'address']);
        });

        Schema::create('user_wallets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('wallet_id')->constrained();
            $table->string('label', 120)->default('');
            $table->boolean('enabled')->default(true);
            $table->unsignedBigInteger('start_block')->default(0);
            $table->timestamps();
            $table->unique(['user_id', 'wallet_id']);
            $table->unique(['id', 'user_id']);
        });

        Schema::create('chat_destinations', function (Blueprint $table): void {
            $table->id();
            $table->string('chat_id', 32)->unique();
            $table->string('kind', 20)->default('private');
            $table->string('approved_label', 120)->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();
        });

        Schema::create('user_chat_destinations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('destination_id')->constrained('chat_destinations');
            $table->boolean('enabled')->default(true);
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'destination_id']);
        });

        Schema::create('wallet_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->unsignedBigInteger('user_wallet_id');
            $table->unsignedBigInteger('destination_id');
            $table->string('direction', 12)->default('both');
            $table->text('min_amount')->default('0');
            $table->boolean('enabled')->default(true);
            $table->string('history_policy', 12)->default('silent');
            $table->timestamps();
            $table->foreign(['user_wallet_id', 'user_id'])
                ->references(['id', 'user_id'])->on('user_wallets');
            $table->foreign(['user_id', 'destination_id'])
                ->references(['user_id', 'destination_id'])->on('user_chat_destinations');
            $table->unique(['user_wallet_id', 'destination_id']);
            $table->unique(['id', 'user_id']);
        });

        Schema::create('watch_sets', function (Blueprint $table): void {
            $table->id();
            $table->string('chain', 16);
            $table->unsignedBigInteger('version');
            $table->bigInteger('cutover_block')->default(-1);
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
            $table->unique(['chain', 'version']);
        });

        Schema::create('watch_set_wallets', function (Blueprint $table): void {
            $table->foreignId('watch_set_id')->constrained();
            $table->foreignId('wallet_id')->constrained();
            $table->primary(['watch_set_id', 'wallet_id']);
        });

        Schema::create('scan_cursors', function (Blueprint $table): void {
            $table->id();
            $table->string('chain', 16);
            $table->string('role', 20)->default('primary');
            $table->string('provider', 64)->default('primary');
            $table->foreignId('watch_set_id')->nullable()->constrained();
            $table->bigInteger('last_block')->default(-1);
            $table->string('last_block_hash', 66)->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->string('pause_reason', 255)->nullable();
            $table->timestamps();
            $table->unique(['chain', 'role', 'provider']);
        });

        Schema::create('scan_ranges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cursor_id')->constrained('scan_cursors');
            $table->foreignId('watch_set_id')->constrained();
            $table->unsignedBigInteger('from_block');
            $table->unsignedBigInteger('to_block');
            $table->string('to_block_hash', 66)->nullable();
            $table->string('event_digest', 64)->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamps();
            $table->unique(['cursor_id', 'watch_set_id', 'from_block', 'to_block']);
        });

        Schema::create('transfers', function (Blueprint $table): void {
            $table->id();
            $table->string('chain', 16);
            $table->string('transaction_hash', 66);
            $table->unsignedInteger('receipt_log_position');
            $table->string('contract_address', 64);
            $table->string('from_address', 64);
            $table->string('to_address', 64);
            $table->text('amount_raw');
            $table->unsignedBigInteger('block_number');
            $table->string('block_hash', 66);
            $table->unsignedBigInteger('block_timestamp');
            $table->string('finality', 20)->default('finalized');
            $table->timestamps();
            $table->unique(['chain', 'transaction_hash', 'receipt_log_position'], 'transfer_event_unique');
            $table->index(['chain', 'block_number']);
            $table->index(['chain', 'from_address', 'block_number']);
            $table->index(['chain', 'to_address', 'block_number']);
        });

        Schema::create('notification_outbox', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transfer_id')->constrained();
            $table->foreignId('destination_id')->constrained('chat_destinations');
            $table->string('notification_kind', 20)->default('transfer');
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->string('lease_token', 64)->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->string('telegram_message_id', 32)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('last_error', 512)->nullable();
            $table->timestamps();
            $table->unique(['transfer_id', 'destination_id', 'notification_kind'], 'outbox_destination_event_unique');
            $table->index(['status', 'available_at', 'lease_expires_at'], 'outbox_ready_index');
        });

        Schema::create('outbox_authorizations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('outbox_id')->constrained('notification_outbox');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('subscription_id');
            $table->unsignedBigInteger('grant_id');
            $table->timestamps();
            $table->foreign(['subscription_id', 'user_id'])
                ->references(['id', 'user_id'])->on('wallet_subscriptions');
            $table->foreign(['grant_id', 'user_id'])
                ->references(['id', 'user_id'])->on('access_grants');
            $table->unique(['outbox_id', 'subscription_id', 'grant_id'], 'outbox_authorizer_unique');
        });

        Schema::create('delivery_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('outbox_id')->constrained('notification_outbox');
            $table->string('outcome', 20);
            $table->string('error_code', 64)->nullable();
            $table->string('error_message', 512)->nullable();
            $table->timestamp('attempted_at');
            $table->timestamps();
        });

        Schema::create('telegram_updates', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('update_id')->unique();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->foreignId('access_key_id')->nullable()->constrained();
            $table->string('result', 32)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('telegram_update_offsets', function (Blueprint $table): void {
            $table->string('worker', 64)->primary();
            $table->unsignedBigInteger('next_update_id')->default(0);
            $table->timestamps();
        });

        Schema::create('audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users');
            $table->string('operator_id', 64)->nullable();
            $table->string('action', 64);
            $table->string('subject_type', 64)->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'audit_events', 'telegram_update_offsets', 'telegram_updates', 'delivery_attempts',
            'outbox_authorizations', 'notification_outbox', 'transfers', 'scan_ranges',
            'scan_cursors', 'watch_set_wallets', 'watch_sets', 'wallet_subscriptions',
            'user_chat_destinations', 'chat_destinations', 'user_wallets', 'wallets',
            'access_grants', 'access_keys', 'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
