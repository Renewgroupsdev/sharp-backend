<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('module', 60)->nullable(); // 'User Management'
            $table->string('action');
            $table->string('target', 100)->nullable();
            $table->text('details')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
            $table->index(['module', 'created_at']);
            $table->index('target');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
