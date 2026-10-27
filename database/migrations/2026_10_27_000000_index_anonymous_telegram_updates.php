<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX telegram_updates_anonymous_update_id_index ON telegram_updates (update_id) WHERE user_id IS NULL AND access_key_id IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX telegram_updates_anonymous_update_id_index');
    }
};
