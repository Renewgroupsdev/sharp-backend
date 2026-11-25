<?php

namespace Database\Seeders;

use App\Enums\AttendanceStatus;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\College;
use App\Models\CommonFloorMessage;
use App\Models\User;
use App\Support\Avatar;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo roster. Every account's password is "sharp2026" (Active, no forced change)
 * so the app can be clicked through straight after seeding.
 */
class DatabaseSeeder extends Seeder
{
    private const PASSWORD = 'sharp2026';

    public function run(): void
    {
        DB::transaction(function () {
            $colleges = $this->colleges();
            // $this->call(BatchSeeder::class);
            // $this->call(CourseSeeder::class);
            $this->users($colleges);
            // $this->attendance();
            // $this->commonFloor();
            AuditLog::create(['module' => 'System', 'action' => 'Database seeded with demo data']);
        });
    }

    /** @return array<string, int> code => id */
    private function colleges(): array
    {
        foreach ([
            ['Fatima College, Madurai', 'FCM', 'Madurai', 'Dr. S. Meenakshi', 'principal@fc.edu.in', '+91 98430 00004'],
            ['Thiagarajar College', 'THC', 'Madurai', null, 'office@thiagarajar.example', '+91 98430 00005'],
            ['Lady Doak College', 'LDC', 'Madurai', null, 'office@ladydoak.example', '+91 98430 00006'],
            ['American College', 'AMC', 'Madurai', null, 'office@americancollege.example', '+91 98430 00007'],
        ] as [$name, $code, $city, $principal, $email, $mobile]) {
            College::updateOrCreate(['code' => $code], [
                'name' => $name, 'city' => $city, 'principal_name' => $principal, 'email' => $email, 'mobile' => $mobile,
            ]);
        }

        return College::pluck('id', 'code')->all();
    }

    private function users(array $c): void
    {
        $b7 = 'SHARP Batch 07';
        $batchId = Batch::firstOrCreate(['name' => $b7])->id;
        $po = 'TNCCI Programme Office';

        // [public_id, name, username, role, college code, org, batch name ('All batches'/null => no batch_id), mobile, email, status, must change]
        $rows = [
            ['SA-001', 'J. Saranya', 'saranya.j', 'super_admin', null, 'TNCCI — Systems Office', 'All batches', '+919841000002', 'saranya@tncci.org'],
            ['SA-002', 'R. Chandrasekaran', 'r.chandra', 'super_admin', null, 'TNCCI — Systems Office', 'All batches', '+91 98410 00001', 'sysadmin@tncci.org'],
            ['AD-001', 'K. Ramesh', 'k.ramesh', 'admin', null, $po, 'All batches', '+91 98••• ••100', 'k.ramesh@tncci.org'],
            ['OB-001', 'J. Meera', 'j.meera', 'ob', null, $po, 'All batches', '+91 98••• ••700', 'j.meera@tncci.org'],
            ['MN-118', 'SG', 'sg.mentor', 'mentor', null, 'Sundaram Textiles Pvt Ltd', $b7, '+91 98••• ••001', 'sg@sundaram.example'],
            ['MN-121', 'R. Venkatesh', 'r.venkatesh', 'mentor', null, 'Venkateshwara Mills', $b7, '+91 99••• ••442', null],
            ['TR-009', 'Ms. Anitha Rajan', 'anitha.rajan', 'trainer', null, 'TNCCI Faculty Panel', 'All batches', '+91 91••• ••330', null],
            ['TR-012', 'Mr. S. Balaji', 's.balaji', 'trainer', null, 'TNCCI Faculty Panel', 'All batches', '+91 95••• ••671', null, UserStatus::Invited, true],
            ['SH2026/FCM/0412', 'Priya R', 'priya.r', 'student', 'FCM', 'Fatima College, Madurai', $b7, '+91 98••• ••412', null],
            ['SH2026/THC/0413', 'Arun Kumar S', 'arun.k', 'student', 'THC', 'Thiagarajar College', $b7, '+91 90••• ••118', null],
            ['SH2026/LDC/0418', 'Divya Bharathi', 'divya.b', 'student', 'LDC', 'Lady Doak College', $b7, '+91 94••• ••277', null],
            ['SH2026/AMC/0431', 'Mohammed Rafi', 'mohammed.r', 'student', 'AMC', 'American College', $b7, '+91 87••• ••905', null, UserStatus::Suspended],
            ['SH2026/FCM/0422', 'Keerthana V', 'keerthana.v', 'student', 'FCM', 'Fatima College, Madurai', $b7, '+91 96••• ••588', null],
            ['MB-001', 'R. Kavitha', 'r.kavitha', 'member', null, 'Kavitha Apparels Pvt Ltd · TNCCI Member', null, '+91 90••• ••512', null],
            ['IN-FCM', 'Fatima College, Madurai', 'fatima.college', 'institution', 'FCM', 'Affiliated college · Dr. S. Meenakshi, Principal', null, '+91 45••• ••210', null],
            ['SA-002', 'Meera Subramanian', 'meera.sa', 'super_admin', null, $po, 'All batches', '+91 98430 00001', 'meera@sharp.tncci.in'],
            ['MB-002', 'Sundaram Textiles Pvt Ltd', 'sundaram.member', 'member', null, 'Sundaram Textiles Pvt Ltd', 'All batches', '+91 98430 00002', 'hr@sundaramtextiles.in'],
            ['OB-002', 'R. Kannan', 'kannan.ob', 'ob', null, 'TNCCI Madurai', 'All batches', '+91 98430 00003', 'kannan@tncci.in'],
            ['IN-001', 'Dr. S. Meenakshi', 'fatima.principal', 'institution', 'FCM', 'Fatima College', 'All batches', '+91 98430 00004', 'principal@fc.edu.in'],
        ];

        foreach ($rows as $r) {
            [$publicId, $name, $username, $role, $college, $org, $batch, $mobile, $email] = $r;

            $mustChange = $r[11] ?? false;

            User::updateOrCreate(['public_id' => $publicId], [
                'name' => $name,
                'username' => $username,
                'password' => self::PASSWORD,
                'must_change_password' => $mustChange,
                'password_changed_at' => $mustChange ? null : now(),
                'role' => Role::from($role),
                'college_id' => $college ? $c[$college] : null,
                'batch_id' => $batch === $b7 ? $batchId : null, // NULL = "All batches"
                'org' => $org,
                'mobile' => $mobile,
                'email' => $email,
                'status' => $r[9] ?? UserStatus::Active,
                'specialist' => match ($role) {
                    'mentor' => 'Industry mentoring',
                    'member' => 'Industry partnership',
                    default => null,
                },
                'short_description' => in_array($role, ['mentor', 'member', 'ob', 'institution'], true) ? "{$name} — {$org}" : null,
                'avatar_initials' => Avatar::initials($name),
                'avatar_color' => Avatar::color($name.$publicId),
            ]);
        }
    }

    private function attendance(): void
    {
        $students = User::students()->pluck('id');
        $mentor = User::where('public_id', 'MN-118')->value('id');
        $ob = User::where('public_id', 'OB-001')->value('id');
        $cycle = [AttendanceStatus::Present, AttendanceStatus::Present, AttendanceStatus::Present, AttendanceStatus::Manual,
            AttendanceStatus::Present, AttendanceStatus::Absent, AttendanceStatus::Present];
        $now = now();

        $rows = [];
        foreach ($students as $id) {
            for ($d = 13; $d >= 0; $d--) {
                $status = $cycle[($d + $id % 10) % count($cycle)];
                $rows[] = [
                    'student_id' => $id,
                    'att_date' => today()->subDays($d)->toDateString(),
                    'status' => $status->value,
                    'reason' => match ($status) {
                        AttendanceStatus::Manual => 'Marked manually — device issue',
                        AttendanceStatus::Absent => 'Informed sick leave',
                        default => null,
                    },
                    'marked_by' => $status === AttendanceStatus::Absent ? $ob : $mentor,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        Attendance::upsert($rows, ['student_id', 'att_date'], ['status', 'reason', 'marked_by', 'updated_at']);
    }

    private function commonFloor(): void
    {
        if (CommonFloorMessage::exists()) {
            return;
        }

        foreach ([
            ['AD-001', 'Welcome to the SHARP Common Floor — this space is shared by every role in the programme.'],
            ['OB-001', 'Reminder: Batch 07 orientation photos are due in the shared drive by Friday.'],
            ['MN-118', 'Great turnout at the Sundaram Textiles plant visit today — thank you all!'],
            ['SH2026/FCM/0412', "Could someone share the notes from yesterday's soft-skills session?"],
        ] as [$publicId, $text]) {
            if ($userId = User::where('public_id', $publicId)->value('id')) {
                CommonFloorMessage::create(['user_id' => $userId, 'message' => $text]);
            }
        }
    }
}
