<?php

namespace Database\Seeders;

use App\Enums\CourseLevel;
use App\Enums\CourseMode;
use App\Enums\CourseScope;
use App\Enums\CourseStatus;
use App\Enums\LessonHost;
use App\Enums\LessonPrivacy;
use App\Enums\RecordStatus;
use App\Models\Batch;
use App\Models\Course;
use App\Models\CourseCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo courses with modules and lessons that use every lesson host (link, rich text and file based), mapped to the
 * running batch so its students are enrolled. Safe to re-run: a course that already has modules is left alone.
 */
class CourseSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $categories = [
                'Soft skills' => 'Communication, networking and workplace behaviour',
                'Career' => 'Resume, interview and placement readiness',
            ];
            foreach ($categories as $name => $description) {
                CourseCategory::firstOrCreate(['name' => $name], ['description' => $description, 'status' => RecordStatus::Active]);
            }
            $category = CourseCategory::pluck('id', 'name');
            $batches = Batch::whereIn('name', ['SHARP Batch 07', 'SHARP Batch 08'])->pluck('id', 'name');

            foreach ($this->courses() as $def) {
                $course = Course::withTrashed()->firstOrCreate(['title' => $def['title']], [
                    'course_category_id' => $category[$def['category']],
                    'hours' => $def['hours'],
                    'mentors' => $def['mentors'],
                    'mode' => $def['mode'],
                    'level' => $def['level'],
                    'scope' => $def['scope'],
                    'status' => CourseStatus::Published,
                    'description' => $def['description'],
                    'outcomes' => implode("\n", $def['outcomes']),
                    'requirements' => implode("\n", $def['requirements']),
                ]);

                if (! $course->chapters()->exists()) {
                    $this->curriculum($course, $def['modules']);
                }
                $course->batches()->syncWithoutDetaching($batches->only($def['batches'])->values()->all());
            }
        });
    }

    private function curriculum(Course $course, array $modules): void
    {
        foreach ($modules as $position => $module) {
            $chapter = $course->chapters()->create([
                'name' => $module['name'],
                'description' => $module['description'],
                'status' => $module['status'] ?? 'Published',
                'position' => $position,
            ]);

            foreach ($module['lessons'] as $order => [$name, $minutes, $host, $payload, $privacy]) {
                $chapter->lessons()->create([
                    'name' => $name,
                    'duration_minutes' => $minutes,
                    'host' => $host,
                    'url' => $payload['url'] ?? null,
                    'content' => $payload['content'] ?? null,
                    'file_name' => $payload['file'][0] ?? null,
                    'file_size' => $payload['file'][1] ?? null,
                    'privacy' => $privacy,
                    'description' => $payload['desc'] ?? null,
                    'position' => $order,
                ]);
            }
        }
    }

    private function courses(): array
    {
        $yt = LessonHost::YouTube->value;
        $video = LessonHost::VideoUpload->value;
        $editor = LessonHost::Editor->value;
        $images = LessonHost::ImageUpload->value;
        $ppt = LessonHost::DocumentUpload->value;
        $pdf = LessonHost::DocumentUpload->value;
        $word = LessonHost::DocumentUpload->value;
        $text = LessonHost::DocumentUpload->value;
        $drive = LessonHost::GoogleDrive->value;
        $public = LessonPrivacy::Public->value;
        $enrolled = LessonPrivacy::Enrolled->value;
        $private = LessonPrivacy::Private->value;

        return [
            [
                'title' => 'Communication Skills',
                'category' => 'Soft skills',
                'hours' => 6,
                'mentors' => ['Ms. Anitha Rajan'],
                'mode' => CourseMode::OnCampus->value,
                'level' => CourseLevel::Beginner->value,
                'scope' => CourseScope::Private->value,
                'description' => 'Speak, listen and write clearly at work: introductions, meetings, email and feedback.',
                'outcomes' => ['Introduce yourself with confidence', 'Write short, polite professional emails', 'Listen actively and give useful feedback'],
                'requirements' => ['Enrolled SHARP student', 'Attend the scheduled sessions'],
                'batches' => ['SHARP Batch 07', 'SHARP Batch 08'],
                'modules' => [
                    [
                        'name' => 'Speaking with confidence',
                        'description' => 'Voice, body language and a clear self-introduction.',
                        'lessons' => [
                            ['Why communication matters', 12, $yt, ['url' => 'https://www.youtube.com/watch?v=HAnw168huqA', 'desc' => 'A short talk on first impressions.'], $public],
                            ['Your 60-second introduction', 15, $editor, ['content' => '<h2>Your 60-second introduction</h2><p>A good introduction answers three questions: <b>who you are</b>, <b>what you do</b> and <b>what you want next</b>.</p><ul><li>Start with your name and college</li><li>Share one strength with an example</li><li>End with what you hope to learn</li></ul><blockquote>Practise it aloud until it sounds like you.</blockquote>'], $enrolled],
                            ['Body language cheat sheet', 10, $images, ['file' => ['body-language.png', '420 KB']], $enrolled],
                            ['Session slides: speaking skills', 20, $ppt, ['file' => ['speaking-skills.pptx', '2.4 MB']], $enrolled],
                        ],
                    ],
                    [
                        'name' => 'Professional writing',
                        'description' => 'Emails and short messages that get a reply.',
                        'lessons' => [
                            ['Writing a professional email', 15, $editor, ['content' => '<h2>Writing a professional email</h2><p>Keep it <b>short</b>, <b>clear</b> and <b>polite</b>.</p><ol><li>Write a specific subject line</li><li>Greet the reader by name</li><li>State your request in the first two lines</li><li>Close with thanks and your name</li></ol><p>Read it once before you press send.</p>'], $enrolled],
                            ['Email etiquette handbook', 25, $pdf, ['file' => ['email-etiquette.pdf', '1.1 MB']], $enrolled],
                            ['Email templates', 10, $word, ['file' => ['email-templates.docx', '86 KB']], $enrolled],
                            ['Useful phrases list', 5, $text, ['file' => ['useful-phrases.txt', '3 KB']], $private],
                        ],
                    ],
                    [
                        'name' => 'Active listening',
                        'description' => 'Listen first, then respond. Work in progress.',
                        'status' => 'Draft',
                        'lessons' => [
                            ['Listening demo', 8, $video, ['url' => 'https://samplelib.com/lib/preview/mp4/sample-10s.mp4'], $enrolled],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Interview Readiness',
                'category' => 'Career',
                'hours' => 8,
                'mentors' => ['R. Venkatesh'],
                'mode' => CourseMode::OffCampus->value,
                'level' => CourseLevel::Intermediate->value,
                'scope' => CourseScope::Public->value,
                'description' => 'Prepare a resume, answer common questions and handle a group discussion.',
                'outcomes' => ['Build a one-page resume', 'Answer "tell me about yourself" well', 'Take part in a group discussion'],
                'requirements' => ['A draft resume to improve'],
                'batches' => ['SHARP Batch 07'],
                'modules' => [
                    [
                        'name' => 'Resume that gets noticed',
                        'description' => 'Structure, keywords and the mistakes to avoid.',
                        'lessons' => [
                            ['Resume basics', 14, $yt, ['url' => 'https://www.youtube.com/watch?v=Tt08KmFfIYQ'], $public],
                            ['Resume checklist', 10, $editor, ['content' => '<h2>Resume checklist</h2><ul><li>One page, clean font</li><li>Contact details at the top</li><li>Projects and internships before hobbies</li><li>No spelling mistakes: ask a friend to proofread</li></ul>'], $public],
                            ['Sample resume', 5, $drive, ['url' => 'https://drive.google.com/file/d/1sampleResumeId/view'], $enrolled],
                        ],
                    ],
                    [
                        'name' => 'Facing the interview',
                        'description' => 'Common questions, mock rounds and group discussion.',
                        'lessons' => [
                            ['Top 20 interview questions', 30, $pdf, ['file' => ['interview-questions.pdf', '980 KB']], $enrolled],
                            ['Group discussion tips', 18, $ppt, ['file' => ['group-discussion.pptx', '3.1 MB']], $enrolled],
                            ['Mock interview recording', 22, $video, ['url' => 'https://samplelib.com/lib/preview/mp4/sample-15s.mp4'], $enrolled],
                            ['Interview day dress code', 6, $images, ['file' => ['dress-code.jpg', '310 KB']], $public],
                        ],
                    ],
                ],
            ],
        ];
    }
}
