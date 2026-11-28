<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A replacement for the built-in SHARP logo (PNG / JPEG data URL); null keeps the built-in one. */
    public function up(): void
    {
        Schema::table('certificate_settings', function (Blueprint $table) {
            $table->text('logo_image')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('certificate_settings', fn (Blueprint $table) => $table->dropColumn('logo_image'));
    }
};
