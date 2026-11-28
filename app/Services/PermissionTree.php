<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * The Roles & Permissions tree. A module or sub-module has page tabs and actions (create / edit / delete ...), and each
 * node lists the role values that may use it. Access to a module is access to its page; an action or a tab is a smaller
 * grant inside it. The front end asks for the signed-in role's slugs and shows only those.
 */
final class PermissionTree
{
    /** Roles that can be assigned (Trainer works in the mobile app only). */
    public const ROLES = ['super_admin', 'admin', 'mentor', 'member', 'ob', 'institution', 'student'];

    private const ALL = ['super_admin', 'admin'];

    /**
     * The default structure and who gets what. `roles` is the access to the module itself; `actions` and `tabs` list their
     * own roles, or null to follow the module.
     *
     * @return list<array<string, mixed>>
     */
    public static function definition(): array
    {
        $a = static fn (string $slug, string $name, ?array $roles = null) => ['slug' => $slug, 'name' => $name, 'roles' => $roles];
        $crud = static fn (string $m, array $create = self::ALL, array $edit = self::ALL, array $delete = []) => [
            $a("$m.create", 'Create', $create), $a("$m.edit", 'Edit', $edit), $a("$m.delete", 'Delete', $delete),
        ];

        return [
            ['slug' => 'a_users', 'name' => 'User Management', 'icon' => 'bi-person-gear', 'roles' => ['super_admin', 'admin', 'mentor', 'member', 'institution'],
                'actions' => $crud('a_users')],
            ['slug' => 'a_orgs', 'name' => 'Institute Management', 'icon' => 'bi-bank', 'roles' => ['super_admin', 'admin', 'member', 'institution'],
                'actions' => $crud('a_orgs'),
                'tabs' => [['slug' => 'a_orgs.tab.institutes', 'name' => 'Institutes', 'icon' => 'bi-bank'], ['slug' => 'a_orgs.tab.batches', 'name' => 'Batches', 'icon' => 'bi-calendar-range']]],
            ['slug' => 'a_courses', 'name' => 'Course Management', 'icon' => 'bi-journal-bookmark', 'roles' => ['super_admin', 'admin', 'ob', 'mentor', 'member', 'institution', 'student'],
                'actions' => [...$crud('a_courses', self::ALL, self::ALL), $a('a_courses.modules', 'Manage modules & lessons', ['super_admin', 'admin', 'mentor'])],
                'tabs' => [['slug' => 'a_courses.tab.courses', 'name' => 'Courses', 'icon' => 'bi-journal-bookmark'], ['slug' => 'a_courses.tab.categories', 'name' => 'Category list', 'icon' => 'bi-tags'], ['slug' => 'a_courses.tab.deleted', 'name' => 'Deleted', 'icon' => 'bi-trash3']],
                'sub_modules' => [
                    ['slug' => 'a_assess', 'name' => 'Assignments, Assessments & Quiz', 'icon' => 'bi-patch-check', 'roles' => ['super_admin', 'admin', 'ob', 'mentor', 'student'],
                        'actions' => [...$crud('a_assess', ['super_admin', 'admin', 'mentor'], ['super_admin', 'admin', 'mentor']), $a('a_assess.grade', 'Grade & review submissions', ['mentor', 'ob']), $a('a_assess.download', 'Download submitted files', self::ALL)],
                        'tabs' => [
                            ['slug' => 'a_assess.tab.list', 'name' => 'List', 'icon' => 'bi-list-ul'],
                            ['slug' => 'a_assess.tab.groups', 'name' => 'Question / assessment groups', 'icon' => 'bi-collection'],
                            ['slug' => 'a_assess.tab.bank', 'name' => 'Question / assessment bank', 'icon' => 'bi-patch-question'],
                            ['slug' => 'a_assess.tab.setup', 'name' => 'Settings', 'icon' => 'bi-sliders'],
                        ]],
                    ['slug' => 'a_attempts', 'name' => 'Quiz Attempts', 'icon' => 'bi-clipboard-data', 'roles' => ['super_admin', 'admin', 'ob', 'mentor'],
                        'actions' => [$a('a_attempts.edit', 'Mark long answers', ['super_admin', 'admin', 'ob', 'mentor'])]],
                    ['slug' => 'a_learn', 'name' => 'Learning & Unlock Module', 'icon' => 'bi-play-circle', 'roles' => ['super_admin', 'admin', 'ob', 'mentor', 'institution', 'student'],
                        'actions' => $crud('a_learn', self::ALL, self::ALL)],
                ]],
            ['slug' => 'a_enroll', 'name' => 'Enrollment', 'icon' => 'bi-person-plus', 'roles' => ['super_admin', 'admin', 'ob', 'mentor'],
                'actions' => $crud('a_enroll')],
            ['slug' => 'a_attend', 'name' => 'Attendance Management', 'icon' => 'bi-clock-history', 'roles' => ['super_admin', 'admin', 'ob', 'mentor', 'institution', 'student'],
                'actions' => $crud('a_attend', ['super_admin', 'admin', 'mentor'], ['super_admin', 'admin', 'mentor']),
                'tabs' => [['slug' => 'a_attend.tab.list', 'name' => 'Attendance list', 'icon' => 'bi-list-check'], ['slug' => 'a_attend.tab.manual', 'name' => 'Mark manual attendance', 'icon' => 'bi-pencil-square']]],
            ['slug' => 'a_certs', 'name' => 'Certificate Management', 'icon' => 'bi-award', 'roles' => ['super_admin', 'admin', 'mentor', 'institution', 'student'],
                'actions' => $crud('a_certs'),
                'tabs' => [
                    ['slug' => 'a_certs.tab.list', 'name' => 'Certificates', 'icon' => 'bi-award'],
                    ['slug' => 'a_certs.tab.templates', 'name' => 'Templates', 'icon' => 'bi-palette', 'roles' => self::ALL],
                    ['slug' => 'a_certs.tab.settings', 'name' => 'Certificate settings', 'icon' => 'bi-sliders', 'roles' => self::ALL],
                ]],
            ['slug' => 'a_comms', 'name' => 'SHARP Community', 'icon' => 'bi-chat-dots', 'roles' => ['super_admin', 'admin', 'ob', 'mentor', 'institution', 'student'],
                'actions' => $crud('a_comms', ['super_admin', 'admin', 'mentor', 'student']),
                'sub_modules' => [
                ]],
            ['slug' => 'a_gallery', 'name' => 'Gallery', 'icon' => 'bi-images', 'roles' => ['super_admin', 'admin', 'ob', 'mentor', 'member', 'institution', 'student'],
                'actions' => $crud('a_gallery', ['super_admin', 'admin', 'ob'], ['super_admin', 'admin', 'ob'], ['super_admin', 'admin'])],
            ['slug' => 'a_notif', 'name' => 'Notification Management', 'icon' => 'bi-bell', 'roles' => ['super_admin', 'admin'],
                'actions' => [$a('a_notif.create', 'Send notification', self::ALL), $a('a_notif.edit', 'Edit rules', self::ALL)]],
            ['slug' => 'a_roles', 'name' => 'Roles & Permissions', 'icon' => 'bi-shield-lock', 'roles' => ['super_admin', 'admin'],
                'actions' => [$a('a_roles.edit', 'Change permissions', self::ALL)]],
            ['slug' => 'a_logs', 'name' => 'Logs Management', 'icon' => 'bi-list-check', 'roles' => ['super_admin', 'admin', 'mentor', 'member', 'institution'], 'actions' => []],
            ['slug' => 'a_reports', 'name' => 'Reports', 'icon' => 'bi-bar-chart', 'roles' => ['super_admin', 'admin', 'ob', 'institution'], 'actions' => []],
        ];
    }

    /** Empties the tree and writes the defaults. */
    public static function seedDefaults(): void
    {
        DB::transaction(function () {
            DB::table('permission_tabs')->delete();
            DB::table('permission_actions')->delete();
            DB::table('permission_modules')->delete();
            foreach (self::definition() as $i => $module) {
                self::insertModule($module, null, $i);
            }
        });
    }

    /** @param  array<string, mixed>  $module */
    private static function insertModule(array $module, ?int $parentId, int $position): void
    {
        $roles = $module['roles'] ?? [];
        $id = DB::table('permission_modules')->insertGetId([
            'parent_id' => $parentId, 'position' => $position, 'slug' => $module['slug'], 'name' => $module['name'], 'icon' => $module['icon'] ?? null,
            'roles' => json_encode($roles), 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($module['actions'] ?? [] as $action) {
            DB::table('permission_actions')->insert(['module_id' => $id, 'slug' => $action['slug'], 'name' => $action['name'], 'roles' => json_encode($action['roles'] ?? $roles), 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ($module['tabs'] ?? [] as $i => $tab) {
            DB::table('permission_tabs')->insert(['module_id' => $id, 'position' => $i, 'slug' => $tab['slug'], 'name' => $tab['name'], 'icon' => $tab['icon'] ?? null, 'roles' => json_encode($tab['roles'] ?? $roles), 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ($module['sub_modules'] ?? [] as $i => $child) {
            self::insertModule($child, $id, $i);
        }
    }

    /**
     * The whole tree for the Assign Permissions screen.
     *
     * @return list<array<string, mixed>>
     */
    public function tree(): array
    {
        $modules = DB::table('permission_modules')->orderBy('position')->get();
        $actions = DB::table('permission_actions')->orderBy('id')->get()->groupBy('module_id');
        $tabs = DB::table('permission_tabs')->orderBy('position')->get()->groupBy('module_id');
        $roles = static fn ($json) => array_values((array) json_decode((string) $json, true));

        $build = function (?int $parent) use (&$build, $modules, $actions, $tabs, $roles): array {
            return $modules->where('parent_id', $parent)->map(fn ($m) => [
                'slug' => $m->slug, 'name' => $m->name, 'icon' => $m->icon, 'roles' => $roles($m->roles),
                'actions' => ($actions[$m->id] ?? collect())->map(fn ($a) => ['slug' => $a->slug, 'name' => $a->name, 'roles' => $roles($a->roles)])->values()->all(),
                'tabs' => ($tabs[$m->id] ?? collect())->map(fn ($t) => ['slug' => $t->slug, 'name' => $t->name, 'icon' => $t->icon, 'roles' => $roles($t->roles)])->values()->all(),
                'sub_modules' => $build($m->id),
            ])->values()->all();
        };

        return $build(null);
    }

    /**
     * Saves who may use each node. Only the role lists change; the structure stays. Super Admin always keeps Roles &
     * Permissions, so the screen can never lock everyone out.
     *
     * @param  array{modules?: array<string, list<string>>, actions?: array<string, list<string>>, tabs?: array<string, list<string>>}  $assign
     */
    public function save(array $assign): void
    {
        $clean = static fn (array $roles, bool $keep = false) => array_values(array_unique(array_merge(array_intersect($roles, self::ROLES), $keep ? ['super_admin'] : [])));
        DB::transaction(function () use ($assign, $clean) {
            foreach (['modules' => 'permission_modules', 'actions' => 'permission_actions', 'tabs' => 'permission_tabs'] as $key => $table) {
                foreach ($assign[$key] ?? [] as $slug => $roles) {
                    $keep = in_array($slug, ['a_roles', 'a_roles.edit'], true);
                    DB::table($table)->where('slug', $slug)->update(['roles' => json_encode($clean((array) $roles, $keep)), 'updated_at' => now()]);
                }
            }
        });
    }

    /**
     * What a role may use: module slugs (including nested ones), action slugs and tab slugs. A tab or an action given to the
     * role opens its module too (and the modules above it), so ticking one tab is enough for the menu item to appear.
     *
     * @return array{modules: list<string>, actions: list<string>, tabs: list<string>}
     */
    public function forRole(string $role): array
    {
        $has = static fn ($json) => in_array($role, (array) json_decode((string) $json, true), true);
        $modules = DB::table('permission_modules')->get(['id', 'parent_id', 'slug', 'roles']);
        $actions = DB::table('permission_actions')->get(['module_id', 'slug', 'roles'])->filter(fn ($r) => $has($r->roles));
        $tabs = DB::table('permission_tabs')->get(['module_id', 'slug', 'roles'])->filter(fn ($r) => $has($r->roles));

        $open = $modules->filter(fn ($m) => $has($m->roles))->pluck('id')->merge($actions->pluck('module_id'))->merge($tabs->pluck('module_id'))->unique()->values()->all();
        $byId = $modules->keyBy('id');
        foreach ($open as $id) {
            for ($parent = $byId[$id]->parent_id ?? null; $parent; $parent = $byId[$parent]->parent_id ?? null) {
                $open[] = $parent;
            }
        }

        return [
            'modules' => $modules->filter(fn ($m) => in_array($m->id, $open, true))->pluck('slug')->values()->all(),
            'actions' => $actions->pluck('slug')->values()->all(),
            'tabs' => $tabs->pluck('slug')->values()->all(),
        ];
    }
}
