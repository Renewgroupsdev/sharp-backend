<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Details staff can set on a certificate that is not issued yet: the date it will carry, a score that replaces the computed
 * one, and wording / signature that differ from the programme settings. Issued certificates are locked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->date('issue_date')->nullable();
            $table->unsignedTinyInteger('score_override')->nullable();
            $table->json('design_override')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->dropColumn(['issue_date', 'score_override', 'design_override']);
        });
    }
};
