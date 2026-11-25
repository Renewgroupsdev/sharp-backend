<?php

use App\Enums\CertificateStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A student's certificate for a course. A Pending row is created when the student finishes the course (100%);
     * an admin then issues it, which assigns the number.
     */
    public function up(): void
    {
        Schema::create('certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('number', 40)->nullable()->unique();
            $table->string('status', 10)->default(CertificateStatus::Pending->value);
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'course_id']);
            $table->index(['course_id', 'status']);
        });

        $values = implode(',', array_map(fn ($v) => "'{$v}'", CertificateStatus::values()));
        DB::statement("ALTER TABLE certificates ADD CONSTRAINT certificates_status_check CHECK (status IN ({$values}))");
    }

    public function down(): void
    {
        Schema::dropIfExists('certificates');
    }
};
