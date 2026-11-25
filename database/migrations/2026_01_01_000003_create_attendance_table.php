<?php

use App\Enums\AttendanceStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->date('att_date');
            $table->string('status', 10);
            $table->string('reason')->nullable();
            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['student_id', 'att_date']);
            $table->index('att_date');
        });

        $values = implode(',', array_map(fn ($c) => "'{$c->value}'", AttendanceStatus::cases()));
        DB::statement("ALTER TABLE attendance ADD CONSTRAINT attendance_status_check CHECK (status IN ({$values}))");
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance');
    }
};
