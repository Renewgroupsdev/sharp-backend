<?php

use App\Enums\AttendanceChoice;
use App\Enums\AttendanceRequestStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A student's Present / Absent report for today, waiting for an admin to mark it. Marking writes the
     * attendance row (see AttendanceRequestService); this table is the inbox and the audit trail of who decided what.
     * One row per student and day: a student may change a pending report, never a resolved one.
     */
    public function up(): void
    {
        Schema::create('attendance_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->date('att_date');
            $table->string('requested_status', 10);            // what the student reported
            $table->string('reason')->nullable();               // required when the student reports Absent
            $table->string('status', 10)->default(AttendanceRequestStatus::Pending->value);
            $table->string('decision', 10)->nullable();         // what the admin marked: the final attendance status
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution_note')->nullable();
            $table->timestamps();

            $table->unique(['student_id', 'att_date']);
            $table->index(['status', 'att_date']);
            $table->index('att_date');
        });

        $quote = fn (array $values) => implode(',', array_map(fn ($v) => "'{$v}'", $values));
        $choices = $quote(AttendanceChoice::values());
        $states = $quote(AttendanceRequestStatus::values());
        DB::statement("ALTER TABLE attendance_requests ADD CONSTRAINT attendance_requests_requested_check CHECK (requested_status IN ({$choices}))");
        DB::statement("ALTER TABLE attendance_requests ADD CONSTRAINT attendance_requests_decision_check CHECK (decision IS NULL OR decision IN ({$choices}))");
        DB::statement("ALTER TABLE attendance_requests ADD CONSTRAINT attendance_requests_status_check CHECK (status IN ({$states}))");
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_requests');
    }
};
