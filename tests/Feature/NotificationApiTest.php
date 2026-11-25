<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Events\NotificationsPushed;
use App\Models\Batch;
use App\Models\Notification;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\NotificationCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $priya;

    private User $arun;

    private Batch $batch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->batch = Batch::create(['name' => 'Batch 1', 'start_date' => today()->subMonth(), 'end_date' => today()->addMonth(), 'seats' => 50, 'status' => 'running']);
        $other = Batch::create(['name' => 'Batch 2', 'start_date' => today()->subMonth(), 'end_date' => today()->addMonth(), 'seats' => 50, 'status' => 'running']);
        $this->admin = User::factory()->role(Role::Admin)->create(['name' => 'Admin']);
        $this->priya = User::factory()->create(['name' => 'Priya', 'batch_id' => $this->batch->id]);
        $this->arun = User::factory()->create(['name' => 'Arun', 'batch_id' => $other->id]);
    }

    public function test_staff_see_one_rule_per_catalog_event_and_others_are_refused(): void
    {
        Sanctum::actingAs($this->admin);
        $rules = $this->getJson('/api/web/notification-rules')->assertOk()->assertJsonCount(count(NotificationCatalog::EVENTS), 'rules')->json('rules');
        $this->assertTrue(collect($rules)->every(fn ($r) => $r['enabled'] && $r['channel'] === 'In-app'));
        $this->assertContains('certificate_issued', array_column($rules, 'event'));

        Sanctum::actingAs($this->priya);
        $this->getJson('/api/web/notification-rules')->assertForbidden();
        $this->postJson('/api/web/notifications/send', ['roles' => ['student'], 'title' => 'Hi'])->assertForbidden();
    }

    public function test_an_event_is_stored_with_the_rules_wording_and_pushed_live(): void
    {
        Event::fake([NotificationsPushed::class]);
        Sanctum::actingAs($this->admin);
        $this->putJson('/api/web/notification-rules/module_unlocked', ['title' => 'Open now', 'body' => '{module} of {course} is open for you.'])->assertOk()
            ->assertJsonPath('rule.title', 'Open now');

        app(NotificationService::class)->notify('module_unlocked', $this->priya->id, ['module' => 'Voice', 'course' => 'Communication'], ['url' => '/app/learning/1']);

        $row = Notification::where('user_id', $this->priya->id)->firstOrFail();
        $this->assertSame('Open now', $row->title);
        $this->assertSame('Voice of Communication is open for you.', $row->body);
        $this->assertSame('learning', $row->type);
        Event::assertDispatched(NotificationsPushed::class, fn ($e) => $e->publicIds === [$this->priya->public_id] && $e->payload['title'] === 'Open now' && $e->payload['url'] === '/app/learning/1');
    }

    public function test_a_person_may_only_listen_to_their_own_notification_channel(): void
    {
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'k', 'broadcasting.connections.reverb.secret' => 's', 'broadcasting.connections.reverb.app_id' => '1']);
        require base_path('routes/channels.php'); // register the channels on the Reverb driver too
        $auth = function (User $as, User $channelOf) {
            $this->app['auth']->forgetGuards();

            return $this->withToken($as->createToken('t')->plainTextToken)->postJson('/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => 'private-'.NotificationsPushed::channelFor($channelOf->public_id)]);
        };

        $auth($this->priya, $this->priya)->assertOk();
        $auth($this->priya, $this->arun)->assertForbidden();
    }

    public function test_a_student_attendance_report_notifies_the_office_and_the_batch_mentor(): void
    {
        $mentor = User::factory()->role(Role::Mentor)->create(['batch_id' => $this->batch->id]);
        $otherMentor = User::factory()->role(Role::Mentor)->create(['batch_id' => $this->arun->batch_id]);

        Sanctum::actingAs($this->priya);
        $this->postJson('/api/web/attendance-requests', ['status' => 'Present'])->assertSuccessful();

        foreach ([$this->admin, $mentor] as $person) {
            $row = Notification::where('user_id', $person->id)->firstOrFail();
            $this->assertSame('attendance', $row->type);
            $this->assertSame('Priya reported present', $row->title);
        }
        $this->assertSame(0, Notification::where('user_id', $otherMentor->id)->count()); // another batch's mentor is left out
        $this->assertSame(0, Notification::where('user_id', $this->priya->id)->count());
    }

    public function test_a_disabled_rule_sends_nothing(): void
    {
        Event::fake([NotificationsPushed::class]);
        Sanctum::actingAs($this->admin);
        $this->putJson('/api/web/notification-rules/certificate_issued', ['enabled' => false])->assertOk()->assertJsonPath('rule.enabled', false);

        app(NotificationService::class)->notify('certificate_issued', $this->priya->id, ['course' => 'X', 'number' => 'C-1']);

        $this->assertSame(0, Notification::count());
        Event::assertNotDispatched(NotificationsPushed::class);

        $this->postJson('/api/web/notification-rules/certificate_issued/reset')->assertOk()->assertJsonPath('rule.enabled', true);
    }

    public function test_staff_send_a_message_to_a_role_a_batch_or_named_people(): void
    {
        Event::fake([NotificationsPushed::class]);
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/web/notifications/send', ['title' => 'No one picked'])->assertUnprocessable();

        $this->postJson('/api/web/notifications/send', ['roles' => ['student'], 'title' => 'Holiday tomorrow', 'body' => 'Enjoy the day.'])
            ->assertCreated()->assertJsonPath('sent', 2);
        $this->postJson('/api/web/notifications/send', ['roles' => ['student'], 'batch_id' => $this->batch->id, 'title' => 'Batch 1 only'])
            ->assertCreated()->assertJsonPath('sent', 1);
        $this->postJson('/api/web/notifications/send', ['users' => [$this->arun->public_id], 'title' => 'Just you'])
            ->assertCreated()->assertJsonPath('sent', 1);

        $this->assertSame(2, Notification::where('user_id', $this->arun->id)->count());             // holiday + just you
        $this->assertSame(2, Notification::where('user_id', $this->priya->id)->count());            // holiday + batch only
        $this->assertSame(0, Notification::where('user_id', $this->admin->id)->count());            // the sender is left out
        Event::assertDispatched(NotificationsPushed::class, 3);

        Sanctum::actingAs($this->priya);
        $inbox = $this->getJson('/api/web/notifications')->assertOk()->json();
        $this->assertSame(2, $inbox['unread']);
        $this->assertSame('message', $inbox['notifications'][0]['type']);
    }
}
