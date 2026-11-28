<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Three more actions so screens can follow permissions instead of role names, and Delete switched off for every role
 * (it was hidden for all users; it can be given back per role on Roles & Permissions). Existing assignments are kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        $extra = [
            ['module' => 'a_courses', 'slug' => 'a_courses.modules', 'name' => 'Manage modules & lessons', 'roles' => ['super_admin', 'admin', 'mentor']],
            ['module' => 'a_assess', 'slug' => 'a_assess.grade', 'name' => 'Grade & review submissions', 'roles' => ['mentor', 'ob']],
            ['module' => 'a_assess', 'slug' => 'a_assess.download', 'name' => 'Download submitted files', 'roles' => ['super_admin', 'admin']],
        ];
        foreach ($extra as $a) {
            $moduleId = DB::table('permission_modules')->where('slug', $a['module'])->value('id');
            if ($moduleId && ! DB::table('permission_actions')->where('slug', $a['slug'])->exists()) {
                DB::table('permission_actions')->insert(['module_id' => $moduleId, 'slug' => $a['slug'], 'name' => $a['name'], 'roles' => json_encode($a['roles']), 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        DB::table('permission_actions')->where('slug', 'like', '%.delete')->update(['roles' => json_encode([]), 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('permission_actions')->whereIn('slug', ['a_courses.modules', 'a_assess.grade', 'a_assess.download'])->delete();
    }
};
