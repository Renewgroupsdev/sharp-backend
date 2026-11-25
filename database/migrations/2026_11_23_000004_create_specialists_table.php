<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** The pick-list behind the user form's Specialist field. A user keeps several of them as a comma-separated text. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('specialists', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->timestamps();
        });
        DB::statement('CREATE UNIQUE INDEX specialists_name_lower_unique ON specialists (LOWER(name))');
        DB::statement('ALTER TABLE users ALTER COLUMN specialist TYPE VARCHAR(255)');

        $now = now();
        $names = ['Startup', 'Document', 'Technologies', 'Leadership'];
        // Keep what was already typed into users' Specialist field, so nothing disappears from the list.
        foreach (DB::table('users')->whereNotNull('specialist')->pluck('specialist') as $text) {
            foreach (explode(',', $text) as $part) {
                $part = trim(preg_replace('/\s+/', ' ', $part));
                if ($part !== '') {
                    $names[] = $part;
                }
            }
        }
        $seen = [];
        foreach ($names as $name) {
            if (isset($seen[mb_strtolower($name)])) {
                continue;
            }
            $seen[mb_strtolower($name)] = true;
            DB::table('specialists')->insert(['name' => mb_substr($name, 0, 80), 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('specialists');
    }
};
