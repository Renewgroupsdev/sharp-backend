<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * users.last_seen_at: when the person last used the app (touched at most once a minute), which drives "online" in chats.
 * Pinned messages become personal: each person pins for themselves and only sees their own pins.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_seen_at')->nullable();
        });
        DB::statement('UPDATE users SET last_seen_at = last_login WHERE last_login IS NOT NULL');

        // Pins made while they were shared cannot be assigned to one person, so they are dropped.
        DB::table('community_pinned_messages')->delete();
        Schema::table('community_pinned_messages', function (Blueprint $table) {
            $table->dropPrimary();
            $table->dropConstrainedForeignId('pinned_by');
        });
        Schema::table('community_pinned_messages', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['group_id', 'user_id', 'message_id']);
        });
    }

    public function down(): void
    {
        DB::table('community_pinned_messages')->delete();
        Schema::table('community_pinned_messages', function (Blueprint $table) {
            $table->dropPrimary();
            $table->dropConstrainedForeignId('user_id');
        });
        Schema::table('community_pinned_messages', function (Blueprint $table) {
            $table->foreignId('pinned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->primary(['group_id', 'message_id']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('last_seen_at');
        });
    }
};
