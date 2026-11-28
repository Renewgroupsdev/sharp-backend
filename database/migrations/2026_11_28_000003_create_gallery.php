<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The Gallery: event photos and videos, and its place in the permission tree (everyone views; staff manage). */
    public function up(): void
    {
        Schema::create('gallery_items', function (Blueprint $table) {
            $table->id();
            $table->string('title', 150);
            $table->text('description')->nullable();
            $table->string('event', 150)->nullable();
            $table->date('event_date')->nullable();
            $table->string('type', 10); // image | video
            $table->string('file_path');
            $table->string('file_name');
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['event_date', 'id']);
        });

        if (! DB::table('permission_modules')->where('slug', 'a_gallery')->exists()) {
            $all = ['super_admin', 'admin', 'ob', 'mentor', 'member', 'institution', 'student'];
            $position = (int) DB::table('permission_modules')->whereNull('parent_id')->max('position') + 1;
            $id = DB::table('permission_modules')->insertGetId([
                'parent_id' => null, 'position' => $position, 'slug' => 'a_gallery', 'name' => 'Gallery', 'icon' => 'bi-images',
                'roles' => json_encode($all), 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ([['create', 'Create', ['super_admin', 'admin', 'ob']], ['edit', 'Edit', ['super_admin', 'admin', 'ob']], ['delete', 'Delete', ['super_admin', 'admin']]] as [$act, $name, $roles]) {
                DB::table('permission_actions')->insert(['module_id' => $id, 'slug' => "a_gallery.$act", 'name' => $name, 'roles' => json_encode($roles), 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('gallery_items');
        DB::table('permission_modules')->where('slug', 'a_gallery')->delete();
    }
};
