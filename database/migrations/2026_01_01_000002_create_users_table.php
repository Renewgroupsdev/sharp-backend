<?php

use App\Enums\BatchStatus;
use App\Enums\Role;
use App\Enums\UserStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('batches', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->unsignedSmallInteger('seats')->default(60);
            $table->unsignedSmallInteger('mentors')->default(0);
            $table->string('status', 20)->default(BatchStatus::Enrolling->value);
            $table->timestamps();
        });

        // Institutes taking part in a batch; no rows = all institutes.
        Schema::create('batch_college', function (Blueprint $table) {
            $table->foreignId('batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('college_id')->constrained()->cascadeOnDelete();
            $table->primary(['batch_id', 'college_id']);
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            // SH2026/FCM/0412, MN-118, AD-001, OB-001, MB-001, IN-FCM, SA-001
            $table->string('public_id', 40)->unique();
            $table->string('name', 150);
            $table->string('username', 60)->unique();
            $table->string('password');
            $table->boolean('must_change_password')->default(true);
            $table->string('role', 20);
            $table->foreignId('college_id')->nullable()->constrained()->nullOnDelete();
            // students, mentors, trainers; NULL = "All batches"
            $table->foreignId('batch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('org', 200)->nullable();
            $table->string('mobile', 20)->nullable();
            $table->string('email', 150)->nullable()->unique(); // NULLs allowed, duplicates not
            $table->string('status', 20)->default(UserStatus::Invited->value);

            // profile (mentor, member, ob, institution)
            $table->string('specialist', 150)->nullable();
            $table->string('short_description', 255)->nullable();
            $table->text('biography')->nullable();
            $table->string('avatar_url', 255)->nullable(); // uploaded file path, max 300 KB
            $table->string('avatar_initials', 4)->nullable();
            $table->string('avatar_color', 10)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_login')->nullable();
            $table->timestamp('password_changed_at')->nullable();
            $table->timestamps();

            $table->index('role');
            $table->index('status');
            $table->index(['role', 'status']);
            $table->index('college_id');
        });

        // initial invite + every "Resend invite"
        Schema::create('user_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('sent_to', 20); // mobile number at send time
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->useCurrent();
            $table->timestamp('accepted_at')->nullable(); // first successful sign-in

            $table->index(['user_id', 'sent_at']);
        });

        // one row per ID prefix; increment inside a transaction
        Schema::create('id_sequences', function (Blueprint $table) {
            $table->string('prefix', 20)->primary(); // 'FCM26-' (student) or 'MN-' (others)
            $table->unsignedInteger('last_no')->default(0);
        });

        DB::statement('ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('.$this->quoted(Role::values()).'))');
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('.$this->quoted(array_column(UserStatus::cases(), 'value')).'))');
        // Case-insensitive search on name/username/public_id (ILIKE '%q%').
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE INDEX users_name_trgm ON users USING gin (name gin_trgm_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('id_sequences');
        Schema::dropIfExists('user_invites');
        Schema::dropIfExists('users');
        Schema::dropIfExists('batch_college');
        Schema::dropIfExists('batches');
    }

    private function quoted(array $values): string
    {
        return implode(',', array_map(fn ($v) => "'{$v}'", $values));
    }
};
