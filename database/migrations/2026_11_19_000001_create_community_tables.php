<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SHARP Community: chat and announcement groups, who is in them, and their messages.
 *
 * Membership is mostly implicit and is worked out when asked (see CommunityAccess): the Common Floor and "All users" hold
 * everyone, a course group follows the batches that take the course, and staff roles sit in every group except personal
 * chats. Only people added by hand (custom groups, extras on a course group, the two people of a personal chat) are rows
 * in community_group_members. The Common Floor chat keeps using common_floor_messages, which the mobile app shares.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->nullable(); // empty for personal chats: they are titled by their two people
            $table->string('kind', 14)->default('Chat'); // Chat | Announcement
            $table->string('source', 10); // floor | course | custom | dm
            $table->foreignId('course_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['source', 'kind']);
        });
        DB::statement('CREATE UNIQUE INDEX community_groups_course_kind_unique ON community_groups (course_id, kind) WHERE course_id IS NOT NULL AND deleted_at IS NULL');
        DB::statement("ALTER TABLE community_groups ADD CONSTRAINT community_groups_kind_check CHECK (kind IN ('Chat','Announcement'))");
        DB::statement("ALTER TABLE community_groups ADD CONSTRAINT community_groups_source_check CHECK (source IN ('floor','course','custom','dm'))");

        Schema::create('community_group_members', function (Blueprint $table) {
            $table->foreignId('group_id')->constrained('community_groups')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->primary(['group_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('community_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('community_groups')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->foreignId('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->index(['group_id', 'id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_messages');
        Schema::dropIfExists('community_group_members');
        Schema::dropIfExists('community_groups');
    }
};
