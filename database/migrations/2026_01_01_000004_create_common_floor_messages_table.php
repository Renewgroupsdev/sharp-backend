<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('common_floor_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('message');
            $table->boolean('flagged')->default(false);
            $table->boolean('muted')->default(false);
            $table->timestamps();

            $table->index(['muted', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('common_floor_messages');
    }
};
