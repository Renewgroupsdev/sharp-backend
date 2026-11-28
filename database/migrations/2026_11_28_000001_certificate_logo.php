<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The SHARP logo on the certificate, and where it sits (left, center or right). */
    public function up(): void
    {
        Schema::table('certificate_settings', function (Blueprint $table) {
            $table->boolean('show_logo')->default(true);
            $table->string('logo_position', 10)->default('center');
        });
    }

    public function down(): void
    {
        Schema::table('certificate_settings', function (Blueprint $table) {
            $table->dropColumn(['show_logo', 'logo_position']);
        });
    }
};
