<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Uploaded logos for the certificate, each with its own position: [{image, position}]. */
    public function up(): void
    {
        Schema::table('certificate_settings', function (Blueprint $table) {
            $table->json('logos')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('certificate_settings', fn (Blueprint $table) => $table->dropColumn('logos'));
    }
};
