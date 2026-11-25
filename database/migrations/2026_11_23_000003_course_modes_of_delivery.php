<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Mode of delivery becomes On Campus / Off Campus / Industrial Visit / Field Trekking. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('courses')->where('mode', 'Face-to-face')->update(['mode' => 'On Campus']);
        DB::table('courses')->whereIn('mode', ['Online', 'Distance learning'])->update(['mode' => 'Off Campus']);
        DB::statement("ALTER TABLE courses ALTER COLUMN mode SET DEFAULT 'On Campus'");
    }

    public function down(): void
    {
        DB::table('courses')->where('mode', 'On Campus')->update(['mode' => 'Face-to-face']);
        DB::table('courses')->whereIn('mode', ['Off Campus', 'Industrial Visit', 'Field Trekking'])->update(['mode' => 'Online']);
        DB::statement("ALTER TABLE courses ALTER COLUMN mode SET DEFAULT 'Online'");
    }
};
