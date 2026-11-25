<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Messages pinned inside a chat. A pin belongs to the chat, so everyone in it sees it. message_id points into
 * community_messages, or into common_floor_messages for the Common Floor, so it has no foreign key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_pinned_messages', function (Blueprint $table) {
            $table->foreignId('group_id')->constrained('community_groups')->cascadeOnDelete();
            $table->unsignedBigInteger('message_id');
            $table->foreignId('pinned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at', 6)->nullable();

            $table->primary(['group_id', 'message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_pinned_messages');
    }
};
