<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Attendance can now be per session as well as per day, and can carry where the student was.
     *
     * - a day-level row (session_id NULL): one per student and day, as before;
     * - a session row: one per student, day and session.
     * The two rules are partial unique indexes, because NULLs are never equal in a plain unique index.
     * Reports (attendance_requests) carry the location the student sent and whether it was inside the geofence.
     */
    public function up(): void
    {
        Schema::table('attendance', function (Blueprint $table) {
            $table->dropUnique(['student_id', 'att_date']);
            $table->foreignId('session_id')->nullable()->after('marked_by')->constrained('class_sessions')->restrictOnDelete();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->unsignedInteger('distance_m')->nullable(); // from the college, when it has a geofence
            $table->boolean('on_site')->nullable();
        });
        DB::statement('CREATE UNIQUE INDEX attendance_day_unique ON attendance (student_id, att_date) WHERE session_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX attendance_session_unique ON attendance (student_id, att_date, session_id) WHERE session_id IS NOT NULL');

        Schema::table('attendance_requests', function (Blueprint $table) {
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->unsignedInteger('distance_m')->nullable();
            $table->boolean('on_site')->nullable();
        });

        Schema::table('colleges', function (Blueprint $table) {
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->unsignedSmallInteger('geofence_radius_m')->default(200); // used only when lat and lng are set
        });
    }

    public function down(): void
    {
        Schema::table('colleges', fn (Blueprint $table) => $table->dropColumn(['lat', 'lng', 'geofence_radius_m']));
        Schema::table('attendance_requests', fn (Blueprint $table) => $table->dropColumn(['lat', 'lng', 'distance_m', 'on_site']));

        DB::statement('DROP INDEX IF EXISTS attendance_session_unique');
        DB::statement('DROP INDEX IF EXISTS attendance_day_unique');
        DB::table('attendance')->whereNotNull('session_id')->delete();
        Schema::table('attendance', function (Blueprint $table) {
            $table->dropConstrainedForeignId('session_id');
            $table->dropColumn(['lat', 'lng', 'distance_m', 'on_site']);
            $table->unique(['student_id', 'att_date']);
        });
    }
};
