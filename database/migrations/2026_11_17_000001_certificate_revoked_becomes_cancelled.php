<?php

use App\Enums\CertificateStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** "Revoked" becomes "Cancelled", and cancelling a certificate records why (a chosen reason and an optional note). */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE certificates DROP CONSTRAINT IF EXISTS certificates_status_check');
        DB::table('certificates')->where('status', 'Revoked')->update(['status' => 'Cancelled']);

        Schema::table('certificates', function (Blueprint $table) {
            $table->renameColumn('revoked_by', 'cancelled_by');
            $table->renameColumn('revoked_at', 'cancelled_at');
            $table->string('cancel_reason', 60)->nullable();
            $table->string('cancel_note', 255)->nullable();
        });

        $values = implode(',', array_map(fn ($v) => "'{$v}'", CertificateStatus::values()));
        DB::statement("ALTER TABLE certificates ADD CONSTRAINT certificates_status_check CHECK (status IN ({$values}))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE certificates DROP CONSTRAINT IF EXISTS certificates_status_check');
        DB::table('certificates')->where('status', 'Cancelled')->update(['status' => 'Revoked']);

        Schema::table('certificates', function (Blueprint $table) {
            $table->dropColumn(['cancel_reason', 'cancel_note']);
            $table->renameColumn('cancelled_by', 'revoked_by');
            $table->renameColumn('cancelled_at', 'revoked_at');
        });

        DB::statement("ALTER TABLE certificates ADD CONSTRAINT certificates_status_check CHECK (status IN ('Pending','Issued','Rejected','Revoked'))");
    }
};
