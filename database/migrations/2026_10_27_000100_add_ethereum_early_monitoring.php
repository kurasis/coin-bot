<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            Schema::create('ethereum_early_state', function (Blueprint $table): void {
                $table->unsignedInteger('id')->primary();
                $table->foreignId('watch_set_id')->constrained('watch_sets');
                $table->unsignedBigInteger('start_block');
                $table->bigInteger('last_block');
                $table->string('last_block_hash', 66);
                $table->unsignedBigInteger('revision')->default(0);
                $table->boolean('reorg_pending')->default(false);
                $table->timestamp('paused_at')->nullable();
                $table->string('pause_reason', 255)->nullable();
                $table->timestamps();
            });
            Schema::create('ethereum_early_events', function (Blueprint $table): void {
                $table->id();
                $table->string('chain', 16)->default('ethereum');
                $table->string('transaction_hash', 66);
                $table->unsignedInteger('receipt_log_position');
                $table->string('contract_address', 64);
                $table->string('from_address', 64);
                $table->string('to_address', 64);
                $table->text('amount_raw');
                $table->unsignedBigInteger('block_number');
                $table->string('block_hash', 66);
                $table->unsignedBigInteger('block_timestamp');
                $table->string('finality', 20)->default('preliminary');
                $table->unsignedInteger('confirmations');
                $table->string('state', 20)->default('observed');
                $table->foreignId('canonical_transfer_id')->nullable()->constrained('transfers');
                $table->timestamps();
                $table->unique(['transaction_hash', 'receipt_log_position', 'block_hash'], 'ethereum_early_event_version_unique');
                $table->index(['state', 'block_number']);
            });
            // SQLite rebuilds this referenced parent table. Deferral preserves
            // existing authorization/attempt children until its replacement is in place.
            DB::statement('PRAGMA defer_foreign_keys=ON');
            Schema::table('notification_outbox', function (Blueprint $table): void {
                $table->unsignedBigInteger('transfer_id')->nullable()->change();
                $table->foreignId('early_event_id')->nullable()->constrained('ethereum_early_events');
                $table->unique(['early_event_id', 'destination_id', 'notification_kind'], 'outbox_early_destination_unique');
            });
            if (DB::select('PRAGMA foreign_key_check') !== []) {
                throw new RuntimeException('Outbox migration would violate existing references.');
            }
            // Dropping/recreating a referenced parent leaves SQLite's deferred
            // violation counter stale even though all references resolve again.
            // The exhaustive check above runs under the same write transaction.
            DB::statement('PRAGMA defer_foreign_keys=OFF');
        });
    }

    public function down(): void
    {
        if (DB::table('ethereum_early_events')->exists()
            || DB::table('notification_outbox')->whereNotNull('early_event_id')->exists()) {
            throw new RuntimeException('Early notification history exists; use a reviewed compatible rollback without deleting delivery evidence.');
        }
        DB::transaction(function (): void {
            DB::statement('PRAGMA defer_foreign_keys=ON');
            Schema::table('notification_outbox', function (Blueprint $table): void {
                $table->dropUnique('outbox_early_destination_unique');
                $table->dropForeign(['early_event_id']);
                $table->dropColumn('early_event_id');
                $table->unsignedBigInteger('transfer_id')->nullable(false)->change();
            });
            Schema::drop('ethereum_early_events');
            Schema::drop('ethereum_early_state');
            if (DB::select('PRAGMA foreign_key_check') !== []) {
                throw new RuntimeException('Outbox rollback would violate existing references.');
            }
            DB::statement('PRAGMA defer_foreign_keys=OFF');
        });
    }
};
