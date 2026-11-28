<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** One image or document per chat message, on both message tables (the Common Floor has its own). */
    public function up(): void
    {
        foreach (['community_messages', 'common_floor_messages'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('attachment_path')->nullable();
                $t->string('attachment_name')->nullable();
                $t->string('attachment_mime', 100)->nullable();
                $t->unsignedInteger('attachment_size')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['community_messages', 'common_floor_messages'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn(['attachment_path', 'attachment_name', 'attachment_mime', 'attachment_size']));
        }
    }
};
