<?php

use App\Enums\CertificateStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Certificate management: one row of programme-wide settings (template, numbering, minimum attendance and score), the
 * mentor's recommendation on each certificate, revocation, and the design / marks frozen at the moment of issue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificate_settings', function (Blueprint $table) {
            $table->id();
            $table->string('template', 20)->default('Classic');
            $table->string('type', 20)->default('Completion');
            $table->string('title', 120)->default('Certificate of Completion');
            $table->text('body');
            $table->string('prefix', 12)->default('SHARP');
            $table->unsignedTinyInteger('min_attendance')->default(75);
            $table->unsignedTinyInteger('min_score')->default(0); // 0 = no minimum
            $table->boolean('show_profile')->default(true);
            $table->boolean('show_qr')->default(true);
            $table->string('signature_name', 80)->nullable();
            $table->string('signature_role', 80)->nullable();
            $table->text('signature_image')->nullable(); // a small PNG / JPEG data URL
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('certificates', function (Blueprint $table) {
            $table->string('mentor_decision', 10)->nullable();
            $table->string('mentor_note', 255)->nullable();
            $table->foreignId('mentor_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('mentor_reviewed_at')->nullable();
            $table->unsignedTinyInteger('score')->nullable();
            $table->unsignedTinyInteger('attendance')->nullable();
            $table->boolean('eligibility_override')->default(false);
            $table->json('design')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
        });

        DB::statement('ALTER TABLE certificates DROP CONSTRAINT IF EXISTS certificates_status_check');
        $values = implode(',', array_map(fn ($v) => "'{$v}'", CertificateStatus::values()));
        DB::statement("ALTER TABLE certificates ADD CONSTRAINT certificates_status_check CHECK (status IN ({$values}))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE certificates DROP CONSTRAINT IF EXISTS certificates_status_check');
        DB::table('certificates')->where('status', 'Revoked')->update(['status' => 'Rejected']);
        DB::statement("ALTER TABLE certificates ADD CONSTRAINT certificates_status_check CHECK (status IN ('Pending','Issued','Rejected'))");

        Schema::table('certificates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('mentor_reviewed_by');
            $table->dropConstrainedForeignId('revoked_by');
            $table->dropColumn(['mentor_decision', 'mentor_note', 'mentor_reviewed_at', 'score', 'attendance', 'eligibility_override', 'design', 'revoked_at']);
        });
        Schema::dropIfExists('certificate_settings');
    }
};
