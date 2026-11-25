<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What each person has done in each chat: pinned it to the top of their list, and how far they have read. A chat with no
 * row has nothing pinned and counts as read up to `community_groups.read_baseline_id`, which is set to the newest message
 * of every existing chat here, so turning this on does not mark all history as unread.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('community_groups', function (Blueprint $table) {
            $table->unsignedBigInteger('read_baseline_id')->default(0);
        });

        Schema::create('community_chat_states', function (Blueprint $table) {
            $table->foreignId('group_id')->constrained('community_groups')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('pinned_at')->nullable();
            $table->unsignedBigInteger('last_read_message_id')->nullable();
            $table->timestamps();

            $table->primary(['group_id', 'user_id']);
            $table->index(['user_id', 'pinned_at']);
        });

        DB::statement('UPDATE community_groups g SET read_baseline_id = COALESCE((SELECT MAX(m.id) FROM community_messages m WHERE m.group_id = g.id), 0)');
        $floorMax = (int) DB::table('common_floor_messages')->max('id');
        DB::table('community_groups')->where('source', 'floor')->update(['read_baseline_id' => $floorMax]);
    }

    public function down(): void
    {
        Schema::dropIfExists('community_chat_states');
        Schema::table('community_groups', function (Blueprint $table) {
            $table->dropColumn('read_baseline_id');
        });
    }
};
