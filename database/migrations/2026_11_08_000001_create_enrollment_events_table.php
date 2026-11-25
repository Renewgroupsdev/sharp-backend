<?php

use App\Enums\EnrollmentEventType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * History of a course being added to / removed from a batch. The batch_course row is deleted on removal, so the
     * trail is keyed by (course, batch) and survives it.
     */
    public function up(): void
    {
        Schema::create('enrollment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('batch_id')->constrained()->cascadeOnDelete();
            $table->string('event', 10);
            $table->string('source', 20)->default('manual'); // manual, bulk, copy, import, course form, batch form
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['course_id', 'batch_id', 'created_at']);
            $table->index('created_at');
        });

        $values = implode(',', array_map(fn ($v) => "'{$v}'", EnrollmentEventType::values()));
        DB::statement("ALTER TABLE enrollment_events ADD CONSTRAINT enrollment_events_event_check CHECK (event IN ({$values}))");
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollment_events');
    }
};
