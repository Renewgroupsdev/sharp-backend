<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Scheduled class sessions are gone: attendance is one row per student and day again. The class_sessions table and the
 * attendance.session_id column are dropped. It refuses to run while attendance marks are still tied to a session, because
 * dropping the column would merge several marks of one day into one.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tied = DB::table('attendance')->whereNotNull('session_id')->count();
        if ($tied > 0) {
            throw new RuntimeException("{$tied} attendance mark(s) belong to a session. Review or delete them before removing sessions.");
        }

        DB::statement('DROP INDEX IF EXISTS attendance_day_unique');
        DB::statement('DROP INDEX IF EXISTS attendance_session_unique');
        Schema::table('attendance', function (Blueprint $table) {
            $table->dropConstrainedForeignId('session_id');
            $table->unique(['student_id', 'att_date']);
        });

        Schema::dropIfExists('class_sessions');
    }

    public function down(): void
    {
        Schema::create('class_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('batch_id')->constrained()->cascadeOnDelete();
            $table->date('session_date');
            $table->time('start_time');
            $table->time('end_time')->nullable();
            $table->string('room', 100)->nullable();
            $table->foreignId('trainer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['batch_id', 'session_date']);
            $table->index(['course_id', 'session_date']);
        });

        Schema::table('attendance', function (Blueprint $table) {
            $table->dropUnique(['student_id', 'att_date']);
            $table->foreignId('session_id')->nullable()->after('marked_by')->constrained('class_sessions')->restrictOnDelete();
        });
        DB::statement('CREATE UNIQUE INDEX attendance_day_unique ON attendance (student_id, att_date) WHERE session_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX attendance_session_unique ON attendance (student_id, att_date, session_id) WHERE session_id IS NOT NULL');
    }
};
