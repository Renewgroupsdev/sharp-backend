<?php

use App\Services\PermissionTree;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roles & Permissions as a tree: modules, sub-modules, and for each of them its page tabs and its actions. Every node
 * lists the roles that may use it. The tree is filled with the defaults when the tables are created.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permission_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('permission_modules')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->string('slug', 60)->unique();
            $table->string('name', 100);
            $table->string('icon', 60)->nullable();
            $table->json('roles')->default('[]');
            $table->timestamps();
        });

        Schema::create('permission_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('module_id')->constrained('permission_modules')->cascadeOnDelete();
            $table->string('slug', 80)->unique();
            $table->string('name', 100);
            $table->json('roles')->default('[]');
            $table->timestamps();
        });

        Schema::create('permission_tabs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('module_id')->constrained('permission_modules')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->string('slug', 80)->unique();
            $table->string('name', 100);
            $table->string('icon', 60)->nullable();
            $table->json('roles')->default('[]');
            $table->timestamps();
        });

        PermissionTree::seedDefaults();
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_tabs');
        Schema::dropIfExists('permission_actions');
        Schema::dropIfExists('permission_modules');
    }
};
