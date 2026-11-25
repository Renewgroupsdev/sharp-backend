<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Assessment;
use App\Models\Attendance;
use App\Models\Batch;
use App\Models\Certificate;
use App\Models\CertificateSetting;
use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\QuizResult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CertificateManagementApiTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/web';

    private const PNG = 'data:image/png;base64,iVBORw0KGgo=';

    private Batch $batch;

    private Course $course;

    private User $priya;

    private User $mentor;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->batch = Batch::create(['name' => 'Batch 1', 'start_date' => today()->subMonth(), 'end_date' => today()->addMonth(), 'seats' => 50, 'status' => 'running']);
        $this->course = Course::create(['title' => 'Communication', 'course_category_id' => CourseCategory::create(['name' => 'Soft'])->id, 'hours' => 6, 'status' => 'Published']);
        $this->course->batches()->sync([$this->batch->id]);
        $this->priya = User::factory()->create(['name' => 'Priya', 'batch_id' => $this->batch->id]);
        $this->mentor = User::factory()->role(Role::Mentor)->create(['batch_id' => $this->batch->id]);
        $this->admin = User::factory()->role(Role::Admin)->create(['name' => 'Admin']);
    }

    private function pending(?User $student = null): Certificate
    {
        return Certificate::create(['user_id' => ($student ?? $this->priya)->id, 'course_id' => $this->course->id]);
    }

    private function attendance(User $student, int $present, int $absent): void
    {
        foreach (range(1, $present) as $i) {
            Attendance::create(['student_id' => $student->id, 'att_date' => today()->subDays($i), 'status' => 'Present']);
        }
        foreach (range(1, $absent) as $i) {
            Attendance::create(['student_id' => $student->id, 'att_date' => today()->subDays(20 + $i), 'status' => 'Absent', 'reason' => 'x']);
        }
    }

    public function test_the_list_carries_live_eligibility_and_filters_by_search(): void
    {
        $this->attendance($this->priya, 1, 1); // 50%
        $this->pending();
        Sanctum::actingAs($this->admin);

        $row = $this->getJson(self::BASE.'/certificates')->assertOk()->json('certificates.0');
        $this->assertSame(50, $row['attendance']);
        $this->assertFalse($row['eligibility']['ok']);
        $this->assertSame(75, $row['eligibility']['min_attendance']);
        $this->assertSame('Classic', $row['design']['template']);

        $this->getJson(self::BASE.'/certificates?q=priya')->assertJsonCount(1, 'certificates');
        $this->getJson(self::BASE.'/certificates?q=nobody')->assertJsonCount(0, 'certificates');
    }

    public function test_issuing_below_the_minimum_needs_an_override_which_is_recorded(): void
    {
        $this->attendance($this->priya, 1, 3); // 25%
        $cert = $this->pending();
        Sanctum::actingAs($this->admin);

        $this->postJson(self::BASE."/certificates/{$cert->id}/issue")->assertUnprocessable();
        $this->assertSame('Pending', $cert->fresh()->status->value);

        $this->postJson(self::BASE."/certificates/{$cert->id}/issue", ['override' => true])->assertOk()
            ->assertJsonPath('certificate.status', 'Issued')->assertJsonPath('certificate.override', true)->assertJsonPath('certificate.attendance', 25);
        $this->assertMatchesRegularExpression('/^SHARP-\d{4}-\d{5}$/', $cert->fresh()->number);
    }

    public function test_the_minimum_score_counts_and_a_student_without_marks_is_not_held_back(): void
    {
        CertificateSetting::current()->update(['min_score' => 60]);
        $quiz = Assessment::create(['type' => 'quiz', 'title' => 'Quiz', 'course' => 'Communication', 'status' => 'published']);
        QuizResult::create(['assessment_id' => $quiz->id, 'student_id' => $this->priya->id, 'marks' => 4, 'total' => 10, 'result_percent' => 40, 'attempts' => 1]);
        $weak = $this->pending();
        $none = $this->pending(User::factory()->create(['batch_id' => $this->batch->id]));
        Sanctum::actingAs($this->admin);

        $this->postJson(self::BASE."/certificates/{$weak->id}/issue")->assertUnprocessable();
        $this->postJson(self::BASE."/certificates/{$none->id}/issue")->assertOk()->assertJsonPath('certificate.score', null);
    }

    public function test_an_issued_certificate_keeps_the_design_it_was_issued_with(): void
    {
        $cert = $this->pending();
        Sanctum::actingAs($this->admin);
        $this->postJson(self::BASE."/certificates/{$cert->id}/issue")->assertOk();

        $this->putJson(self::BASE.'/certificate-settings', $this->settings(['template' => 'Modern', 'title' => 'New title', 'prefix' => 'tn']))->assertOk()->assertJsonPath('settings.prefix', 'TN');

        $this->getJson(self::BASE.'/certificates')->assertJsonPath('certificates.0.design.template', 'Classic')->assertJsonPath('certificates.0.design.title', 'Certificate of Completion');
        $next = $this->pending(User::factory()->create(['batch_id' => $this->batch->id]));
        $this->postJson(self::BASE."/certificates/{$next->id}/issue")->assertOk()->assertJsonPath('certificate.design.template', 'Modern');
        $this->assertStringStartsWith('TN-', $next->fresh()->number);
    }

    public function test_the_mentor_recommends_for_their_own_batch_and_cannot_issue(): void
    {
        $cert = $this->pending();
        $other = Batch::create(['name' => 'Batch 2', 'start_date' => today(), 'end_date' => today()->addMonth(), 'seats' => 5, 'status' => 'running']);
        $stranger = $this->pending(User::factory()->create(['batch_id' => $other->id]));

        Sanctum::actingAs($this->mentor);
        $this->postJson(self::BASE."/certificates/{$cert->id}/review", ['decision' => 'returned'])->assertUnprocessable(); // a note is needed
        $this->postJson(self::BASE."/certificates/{$cert->id}/review", ['decision' => 'returned', 'note' => 'Attendance missing'])->assertOk()
            ->assertJsonPath('certificate.mentor.decision', 'returned')->assertJsonPath('certificate.mentor.note', 'Attendance missing');
        $this->postJson(self::BASE."/certificates/{$cert->id}/review", ['decision' => 'approved'])->assertOk()->assertJsonPath('certificate.mentor.decision', 'approved');
        $this->postJson(self::BASE."/certificates/{$stranger->id}/review", ['decision' => 'approved'])->assertForbidden();
        $this->postJson(self::BASE."/certificates/{$cert->id}/issue")->assertForbidden();
        $this->assertSame('Pending', $cert->fresh()->status->value);
    }

    public function test_cancelling_needs_a_reason_works_only_on_issued_and_verification_reports_it(): void
    {
        $cert = $this->pending();
        Sanctum::actingAs($this->admin);
        $this->postJson(self::BASE."/certificates/{$cert->id}/cancel", ['reason' => 'Issued in error'])->assertUnprocessable(); // not issued yet
        $this->postJson(self::BASE."/certificates/{$cert->id}/issue")->assertOk();
        $number = $cert->fresh()->number;

        $this->app['auth']->forgetGuards();
        $this->getJson(self::BASE."/verify/{$number}")->assertOk()->assertJsonPath('valid', true)->assertJsonPath('name', 'Priya')->assertJsonMissingPath('student_id');

        Sanctum::actingAs($this->admin);
        $this->postJson(self::BASE."/certificates/{$cert->id}/cancel")->assertUnprocessable(); // no reason
        $this->postJson(self::BASE."/certificates/{$cert->id}/cancel", ['reason' => 'Because'])->assertUnprocessable(); // not a listed reason
        $this->postJson(self::BASE."/certificates/{$cert->id}/cancel", ['reason' => 'Other'])->assertUnprocessable(); // Other needs a note
        $this->assertSame('Issued', $cert->fresh()->status->value);

        $this->postJson(self::BASE."/certificates/{$cert->id}/cancel", ['reason' => 'Wrong details on the certificate', 'note' => 'Course name misspelt'])->assertOk()
            ->assertJsonPath('certificate.status', 'Cancelled')->assertJsonPath('certificate.cancel_reason', 'Wrong details on the certificate')
            ->assertJsonPath('certificate.cancel_note', 'Course name misspelt');
        $this->assertDatabaseHas('notifications', ['user_id' => $this->priya->id, 'title' => 'Your certificate was cancelled']);
        $this->postJson(self::BASE."/certificates/{$cert->id}/issue")->assertUnprocessable();
        $this->postJson(self::BASE."/certificates/{$cert->id}/reject")->assertUnprocessable();
        $this->postJson(self::BASE."/certificates/{$cert->id}/cancel", ['reason' => 'Issued in error'])->assertUnprocessable(); // already cancelled

        $this->app['auth']->forgetGuards();
        $this->getJson(self::BASE."/verify/{$number}")->assertOk()->assertJsonPath('valid', false)->assertJsonPath('status', 'Cancelled')->assertJsonMissingPath('cancel_reason');
        $this->getJson(self::BASE.'/verify/SHARP-0000-00000')->assertNotFound()->assertJsonPath('valid', false);
    }

    public function test_the_other_reason_is_stored_from_the_note_and_the_list_of_reasons_is_served_to_staff(): void
    {
        $cert = $this->pending();
        Sanctum::actingAs($this->admin);
        $this->postJson(self::BASE."/certificates/{$cert->id}/issue")->assertOk();

        $this->getJson(self::BASE.'/certificate-cancel-reasons')->assertOk()->assertJsonPath('reasons.0', 'Issued in error')->assertJsonPath('reasons.6', 'Other');
        $this->postJson(self::BASE."/certificates/{$cert->id}/cancel", ['reason' => 'Other', 'note' => 'Course withdrawn by the institute'])->assertOk()
            ->assertJsonPath('certificate.cancel_reason', 'Other')->assertJsonPath('certificate.cancel_note', 'Course withdrawn by the institute');

        Sanctum::actingAs($this->mentor);
        $this->getJson(self::BASE.'/certificate-cancel-reasons')->assertForbidden();
        $this->postJson(self::BASE."/certificates/{$cert->id}/cancel", ['reason' => 'Issued in error'])->assertForbidden();
    }

    public function test_settings_are_staff_only_and_validated(): void
    {
        Sanctum::actingAs($this->mentor);
        $this->getJson(self::BASE.'/certificate-settings')->assertForbidden();

        Sanctum::actingAs($this->admin);
        $this->getJson(self::BASE.'/certificate-settings')->assertOk()->assertJsonPath('settings.min_attendance', 75)->assertJsonPath('settings.template', 'Classic');

        $this->putJson(self::BASE.'/certificate-settings', $this->settings(['min_attendance' => 101]))->assertUnprocessable();
        $this->putJson(self::BASE.'/certificate-settings', $this->settings(['template' => 'Neon']))->assertUnprocessable();
        $this->putJson(self::BASE.'/certificate-settings', $this->settings(['signature_image' => 'data:image/svg+xml;base64,PHN2Zz4=']))->assertUnprocessable();

        $this->putJson(self::BASE.'/certificate-settings', $this->settings(['signature_image' => self::PNG, 'min_attendance' => 80]))->assertOk()
            ->assertJsonPath('settings.signature_image', self::PNG)->assertJsonPath('settings.min_attendance', 80);
        $this->putJson(self::BASE.'/certificate-settings', $this->settings())->assertOk()->assertJsonPath('settings.signature_image', self::PNG); // not sent: kept
        $this->putJson(self::BASE.'/certificate-settings', $this->settings(['remove_signature_image' => true]))->assertOk()->assertJsonPath('settings.signature_image', null);
    }

    public function test_a_student_sees_only_their_own_certificates_without_staff_fields(): void
    {
        $mine = $this->pending();
        $this->pending(User::factory()->create(['batch_id' => $this->batch->id]));

        Sanctum::actingAs($this->priya);
        $res = $this->getJson(self::BASE.'/my/certificates')->assertOk()->assertJsonCount(1, 'certificates');
        $this->assertSame($mine->id, $res->json('certificates.0.id'));
        $this->assertNull($res->json('certificates.0.mentor'));
        $this->assertNull($res->json('certificates.0.eligibility'));

        $this->getJson(self::BASE.'/certificates')->assertForbidden();
    }

    public function test_staff_create_a_certificate_once_per_student_and_course(): void
    {
        Sanctum::actingAs($this->mentor);
        $this->postJson(self::BASE.'/certificates', ['student_id' => $this->priya->public_id, 'course_id' => $this->course->id])->assertForbidden();

        Sanctum::actingAs($this->admin);
        $this->postJson(self::BASE.'/certificates', ['student_id' => $this->priya->public_id, 'course_id' => $this->course->id])->assertCreated()->assertJsonPath('certificate.status', 'Pending');
        $this->postJson(self::BASE.'/certificates', ['student_id' => $this->priya->public_id, 'course_id' => $this->course->id])->assertUnprocessable();
        $this->postJson(self::BASE.'/certificates', ['student_id' => 'nope', 'course_id' => $this->course->id])->assertNotFound();
    }

    public function test_staff_edit_the_details_of_a_pending_certificate_and_issue_uses_them(): void
    {
        $cert = $this->pending();
        Sanctum::actingAs($this->admin);

        $date = today()->subDays(3)->toDateString();
        $res = $this->putJson(self::BASE."/certificates/{$cert->id}", [
            'issue_date' => $date, 'score' => 91,
            'design' => ['template' => 'Minimal', 'type' => 'Merit', 'title' => 'Merit award', 'body' => 'Well done {student}', 'signature_name' => 'A. Principal', 'signature_role' => 'Principal'],
        ])->assertOk()
            ->assertJsonPath('certificate.issue_date', $date)->assertJsonPath('certificate.score', 91)
            ->assertJsonPath('certificate.design.template', 'Minimal')->assertJsonPath('certificate.design.signature_name', 'A. Principal')
            ->assertJsonPath('certificate.edited', true);
        $this->assertSame('Programme Administrator', CertificateSetting::current()->signature_role); // the programme settings are untouched

        $issued = $this->postJson(self::BASE."/certificates/{$cert->id}/issue")->assertOk()
            ->assertJsonPath('certificate.score', 91)->assertJsonPath('certificate.design.title', 'Merit award')->json('certificate');
        $this->assertSame($date, substr($issued['issued_at'], 0, 10));
    }

    public function test_only_what_differs_from_the_settings_is_kept_as_an_override(): void
    {
        $cert = $this->pending();
        Sanctum::actingAs($this->admin);

        $same = CertificateSetting::current()->design() + [];
        $this->putJson(self::BASE."/certificates/{$cert->id}", ['design' => [
            'template' => $same['template'], 'type' => $same['type'], 'title' => $same['title'], 'body' => $same['body'],
            'signature_name' => $same['signature_name'], 'signature_role' => 'Registrar',
        ]])->assertOk();
        $this->assertSame(['signature_role' => 'Registrar'], $cert->fresh()->design_override);

        $this->putJson(self::BASE."/certificates/{$cert->id}", ['design' => [
            'template' => $same['template'], 'type' => $same['type'], 'title' => $same['title'], 'body' => $same['body'],
            'signature_name' => $same['signature_name'], 'signature_role' => $same['signature_role'],
        ]])->assertOk()->assertJsonPath('certificate.edited', false);
        $this->assertNull($cert->fresh()->design_override);
    }

    public function test_a_certificate_can_have_its_own_minimum_attendance_signature_and_switches(): void
    {
        $this->attendance($this->priya, 2, 2); // 50%, below the programme's 75%
        $cert = $this->pending();
        Sanctum::actingAs($this->admin);

        $same = CertificateSetting::current()->design();
        $design = fn (array $over = []) => $over + [
            'template' => $same['template'], 'type' => $same['type'], 'title' => $same['title'], 'body' => $same['body'],
            'signature_name' => $same['signature_name'], 'signature_role' => $same['signature_role'], 'show_profile' => true, 'show_qr' => true,
        ];

        $this->postJson(self::BASE."/certificates/{$cert->id}/issue")->assertUnprocessable();

        $this->putJson(self::BASE."/certificates/{$cert->id}", ['min_attendance' => 40, 'design' => $design(['show_qr' => false, 'show_profile' => false, 'signature_image' => self::PNG])])->assertOk()
            ->assertJsonPath('certificate.eligibility.ok', true)->assertJsonPath('certificate.eligibility.min_attendance', 40)
            ->assertJsonPath('certificate.design.show_qr', false)->assertJsonPath('certificate.design.signature_image', self::PNG);
        $this->assertSame(['show_profile' => false, 'show_qr' => false, 'signature_image' => self::PNG], $cert->fresh()->design_override);

        $this->putJson(self::BASE."/certificates/{$cert->id}", ['design' => $design(['signature_image' => 'data:image/svg+xml;base64,PHN2Zz4='])])->assertUnprocessable();

        $this->postJson(self::BASE."/certificates/{$cert->id}/issue")->assertOk()->assertJsonPath('certificate.design.show_qr', false);

        $this->putJson(self::BASE.'/certificate-settings', $this->settings(['min_attendance' => 75]))->assertOk(); // the programme setting is untouched
        $this->assertSame(75, CertificateSetting::current()->min_attendance);
    }

    public function test_staff_delete_a_certificate_that_is_not_issued_and_an_issued_one_must_be_cancelled_first(): void
    {
        $pending = $this->pending();
        $issued = $this->pending(User::factory()->create(['batch_id' => $this->batch->id]));

        Sanctum::actingAs($this->mentor);
        $this->deleteJson(self::BASE."/certificates/{$pending->id}")->assertForbidden();

        Sanctum::actingAs($this->admin);
        $this->deleteJson(self::BASE."/certificates/{$pending->id}")->assertOk();
        $this->assertDatabaseMissing('certificates', ['id' => $pending->id]);

        $this->postJson(self::BASE."/certificates/{$issued->id}/issue")->assertOk();
        $this->deleteJson(self::BASE."/certificates/{$issued->id}")->assertUnprocessable();
        $this->assertDatabaseHas('certificates', ['id' => $issued->id]);

        $this->postJson(self::BASE."/certificates/{$issued->id}/cancel", ['reason' => 'Duplicate certificate'])->assertOk();
        $this->deleteJson(self::BASE."/certificates/{$issued->id}")->assertOk();
        $this->assertDatabaseMissing('certificates', ['id' => $issued->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'Deleted certificate']);
    }

    public function test_an_entered_score_decides_eligibility(): void
    {
        CertificateSetting::current()->update(['min_score' => 60]);
        $cert = $this->pending();
        Sanctum::actingAs($this->admin);

        $this->putJson(self::BASE."/certificates/{$cert->id}", ['score' => 40])->assertOk()->assertJsonPath('certificate.eligibility.ok', false);
        $this->postJson(self::BASE."/certificates/{$cert->id}/issue")->assertUnprocessable();

        $this->putJson(self::BASE."/certificates/{$cert->id}", ['score' => 75])->assertOk()->assertJsonPath('certificate.eligibility.ok', true);
        $this->postJson(self::BASE."/certificates/{$cert->id}/issue")->assertOk()->assertJsonPath('certificate.score', 75);
    }

    public function test_an_issued_certificate_is_locked_and_edits_are_validated_and_staff_only(): void
    {
        $cert = $this->pending();
        Sanctum::actingAs($this->mentor);
        $this->putJson(self::BASE."/certificates/{$cert->id}", ['score' => 80])->assertForbidden();

        Sanctum::actingAs($this->admin);
        $this->putJson(self::BASE."/certificates/{$cert->id}", ['issue_date' => today()->addDay()->toDateString()])->assertUnprocessable();
        $this->putJson(self::BASE."/certificates/{$cert->id}", ['score' => 101])->assertUnprocessable();
        $this->putJson(self::BASE."/certificates/{$cert->id}", ['design' => ['template' => 'Neon', 'type' => 'Merit', 'title' => 'x', 'body' => 'y']])->assertUnprocessable();

        $this->postJson(self::BASE."/certificates/{$cert->id}/issue")->assertOk();
        $this->putJson(self::BASE."/certificates/{$cert->id}", ['score' => 80])->assertUnprocessable();
    }

    /** @param array<string, mixed> $over */
    private function settings(array $over = []): array
    {
        return $over + [
            'template' => 'Classic', 'type' => 'Completion', 'title' => 'Certificate of Completion', 'body' => CertificateSetting::DEFAULT_BODY,
            'prefix' => 'SHARP', 'min_attendance' => 75, 'min_score' => 0, 'show_profile' => true, 'show_qr' => true,
            'signature_name' => 'K. Ramesh', 'signature_role' => 'Programme Administrator',
        ];
    }
}
