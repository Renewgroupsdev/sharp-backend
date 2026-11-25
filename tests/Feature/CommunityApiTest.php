<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Events\CommunityChatsChanged;
use App\Events\CommunityMessagePosted;
use App\Events\CommunityMessageRemoved;
use App\Models\Batch;
use App\Models\CommunityGroup;
use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CommunityApiTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/web/community';

    protected Batch $batch;

    protected Batch $other;

    protected Course $course;

    protected User $admin;

    protected User $mentor;

    protected User $priya;

    protected User $arun;

    protected function setUp(): void
    {
        parent::setUp();

        $this->batch = Batch::create(['name' => 'Batch 1', 'start_date' => today()->subMonth(), 'end_date' => today()->addMonth(), 'seats' => 50, 'status' => 'running']);
        $this->other = Batch::create(['name' => 'Batch 2', 'start_date' => today()->subMonth(), 'end_date' => today()->addMonth(), 'seats' => 50, 'status' => 'running']);
        $this->course = Course::create(['title' => 'Communication', 'course_category_id' => CourseCategory::create(['name' => 'Soft'])->id, 'hours' => 6, 'status' => 'Published']);
        $this->course->batches()->sync([$this->batch->id]);

        $this->admin = User::factory()->role(Role::Admin)->create(['name' => 'Admin']);
        $this->mentor = User::factory()->role(Role::Mentor)->create(['name' => 'Mentor', 'batch_id' => $this->batch->id]);
        $this->priya = User::factory()->create(['name' => 'Priya', 'batch_id' => $this->batch->id]);
        $this->arun = User::factory()->create(['name' => 'Arun', 'batch_id' => $this->other->id]);
    }

    protected function group(string $name = 'Interview prep', string $kind = 'Chat', array $members = [], ?User $by = null): int
    {
        Sanctum::actingAs($by ?? $this->admin);

        return $this->postJson(self::BASE.'/groups', ['name' => $name, 'kind' => $kind, 'members' => $members ?: [$this->priya->public_id]])->assertCreated()->json('group.id');
    }

    private function titles(string $kind = 'Chat'): array
    {
        return collect($this->getJson(self::BASE."/groups?kind={$kind}")->assertOk()->json('groups'))->pluck('title')->all();
    }

    public function test_the_org_wide_and_course_groups_exist_without_being_created(): void
    {
        Sanctum::actingAs($this->admin);

        $chat = collect($this->getJson(self::BASE.'/groups?kind=Chat')->assertOk()->json('groups'));
        $this->assertSame(['Common Floor', 'Communication'], $chat->pluck('title')->all());
        $this->assertSame('All users', $this->getJson(self::BASE.'/groups?kind=Announcement')->json('groups.0.title'));

        $floor = $chat->firstWhere('source', 'floor');
        $this->assertSame(User::count(), $floor['members_total']);
        $course = $chat->firstWhere('source', 'course');
        $this->assertSame(3, $course['members_total']); // Priya + the mentor of the batch + the admin who sits in every group

        $this->assertCount(2, CommunityGroup::where('course_id', $this->course->id)->get()); // a chat and an announcement group

        $this->course->update(['title' => 'Communication Skills']);
        $this->assertContains('Communication Skills', $this->titles());

        Course::create(['title' => 'Unused', 'course_category_id' => $this->course->course_category_id, 'hours' => 1, 'status' => 'Published']);
        $this->assertNotContains('Unused', $this->titles()); // no batch takes it, so no group yet
    }

    public function test_staff_create_a_custom_group_and_it_needs_a_name_kind_and_members(): void
    {
        $id = $this->group('Interview prep');

        $this->getJson(self::BASE."/groups/{$id}")->assertOk()
            ->assertJsonPath('group.title', 'Interview prep')->assertJsonPath('group.members_total', 2) // Priya + the admin
            ->assertJsonPath('group.created_by', 'Admin');

        $this->postJson(self::BASE.'/groups', ['name' => 'interview prep', 'kind' => 'Chat', 'members' => [$this->priya->public_id]])->assertUnprocessable(); // name taken
        $this->postJson(self::BASE.'/groups', ['name' => 'X', 'kind' => 'Chat', 'members' => [$this->priya->public_id]])->assertUnprocessable();
        $this->postJson(self::BASE.'/groups', ['name' => 'No people', 'kind' => 'Chat', 'members' => []])->assertUnprocessable();
        $this->postJson(self::BASE.'/groups', ['name' => 'Odd kind', 'kind' => 'Forum', 'members' => [$this->priya->public_id]])->assertUnprocessable();
    }

    public function test_who_may_create_a_group(): void
    {
        Sanctum::actingAs($this->priya);
        $this->postJson(self::BASE.'/groups', ['name' => 'Mine', 'kind' => 'Chat', 'members' => [$this->arun->public_id]])->assertForbidden();
        Sanctum::actingAs($this->mentor);
        $this->postJson(self::BASE.'/groups', ['name' => 'Mine', 'kind' => 'Chat', 'members' => [$this->arun->public_id]])->assertForbidden();

        $member = User::factory()->role(Role::Member)->create();
        $this->group('Member group', 'Chat', [$this->arun->public_id], $member);
    }

    public function test_a_person_sees_only_the_groups_they_belong_to(): void
    {
        $custom = $this->group('Interview prep', 'Chat', [$this->priya->public_id]);

        Sanctum::actingAs($this->priya);
        $this->assertEqualsCanonicalizing(['Common Floor', 'Communication', 'Interview prep'], $this->titles());
        $this->getJson(self::BASE."/groups/{$custom}")->assertOk();

        Sanctum::actingAs($this->arun); // other batch, not in the custom group
        $this->assertSame(['Common Floor'], $this->titles());
        $this->getJson(self::BASE."/groups/{$custom}")->assertForbidden();
        $this->getJson(self::BASE."/groups/{$custom}/messages")->assertForbidden();
    }

    public function test_posting_follows_the_group_rules(): void
    {
        $chat = $this->group('Interview prep', 'Chat', [$this->priya->public_id]);
        $news = $this->group('Notices', 'Announcement', [$this->priya->public_id]);

        Sanctum::actingAs($this->priya);
        $this->postJson(self::BASE."/groups/{$chat}/messages", ['body' => '  Hello all  '])->assertCreated()
            ->assertJsonPath('message.body', 'Hello all')->assertJsonPath('message.author.name', 'Priya');
        $this->postJson(self::BASE."/groups/{$chat}/messages", ['body' => ''])->assertUnprocessable();
        $this->postJson(self::BASE."/groups/{$news}/messages", ['body' => 'Hi'])->assertUnprocessable(); // members read announcements only

        Sanctum::actingAs($this->arun);
        $this->postJson(self::BASE."/groups/{$chat}/messages", ['body' => 'Let me in'])->assertForbidden();

        Sanctum::actingAs($this->admin);
        $this->postJson(self::BASE."/groups/{$news}/messages", ['body' => 'Quiz on Friday'])->assertCreated();

        Sanctum::actingAs($this->priya);
        $this->getJson(self::BASE."/groups/{$news}/messages")->assertOk()->assertJsonCount(1, 'messages')->assertJsonPath('messages.0.body', 'Quiz on Friday');
    }

    public function test_the_group_creator_can_post_announcements_even_without_being_staff(): void
    {
        $member = User::factory()->role(Role::Member)->create(['name' => 'Org member']);
        $news = $this->group('Org news', 'Announcement', [$this->priya->public_id], $member);

        $this->postJson(self::BASE."/groups/{$news}/messages", ['body' => 'From the organisation'])->assertCreated();
    }

    public function test_the_common_floor_chat_is_stored_where_the_mobile_app_reads_it(): void
    {
        Sanctum::actingAs($this->priya);
        $floor = collect($this->getJson(self::BASE.'/groups')->json('groups'))->firstWhere('source', 'floor');
        $floorChat = collect($this->getJson(self::BASE.'/groups?kind=Chat')->json('groups'))->firstWhere('source', 'floor')['id'];

        $this->postJson(self::BASE."/groups/{$floorChat}/messages", ['body' => 'Hello floor'])->assertCreated();

        $this->assertDatabaseHas('common_floor_messages', ['user_id' => $this->priya->id, 'message' => 'Hello floor']);
        $this->assertDatabaseCount('community_messages', 0);
        $this->getJson('/api/web/common-floor')->assertOk()->assertJsonFragment(['message' => 'Hello floor']);
        $this->assertNotNull($floor);
    }

    public function test_only_staff_remove_messages_and_a_removed_message_disappears(): void
    {
        $chat = $this->group('Interview prep', 'Chat', [$this->priya->public_id]);
        Sanctum::actingAs($this->priya);
        $id = $this->postJson(self::BASE."/groups/{$chat}/messages", ['body' => 'Oops'])->json('message.id');

        $this->deleteJson(self::BASE."/groups/{$chat}/messages/{$id}")->assertForbidden();

        Sanctum::actingAs($this->admin);
        $this->deleteJson(self::BASE."/groups/{$chat}/messages/{$id}")->assertOk();
        $this->getJson(self::BASE."/groups/{$chat}/messages")->assertJsonCount(0, 'messages');
        $this->assertDatabaseHas('community_messages', ['id' => $id]); // hidden, not erased
        $this->deleteJson(self::BASE."/groups/{$chat}/messages/{$id}")->assertNotFound();
    }

    public function test_members_can_be_added_and_removed_by_hand_only(): void
    {
        $custom = $this->group('Interview prep', 'Chat', [$this->priya->public_id]);

        $this->postJson(self::BASE."/groups/{$custom}/members", ['members' => [$this->arun->public_id, $this->priya->public_id]])->assertOk()
            ->assertJsonPath('group.members_total', 3); // Priya, Arun and the admin; Priya is not counted twice
        $this->postJson(self::BASE."/groups/{$custom}/members", ['members' => []])->assertUnprocessable();

        $this->postJson(self::BASE."/groups/{$custom}/members/remove", ['member' => $this->arun->public_id])->assertOk()->assertJsonPath('group.members_total', 2);
        $this->postJson(self::BASE."/groups/{$custom}/members/remove", ['member' => $this->admin->public_id])->assertUnprocessable(); // staff are in every group
        $this->postJson(self::BASE."/groups/{$custom}/members/remove", ['member' => $this->arun->public_id])->assertUnprocessable(); // no longer in it

        $courseChat = collect($this->getJson(self::BASE.'/groups?source=course&kind=Chat')->json('groups'))->first()['id'];
        $this->postJson(self::BASE."/groups/{$courseChat}/members/remove", ['member' => $this->priya->public_id])->assertUnprocessable(); // there by enrolment
        $this->postJson(self::BASE."/groups/{$courseChat}/members", ['members' => [$this->arun->public_id]])->assertOk();
        $this->postJson(self::BASE."/groups/{$courseChat}/members/remove", ['member' => $this->arun->public_id])->assertOk(); // added by hand, so removable
    }

    public function test_the_member_list_flags_who_can_be_removed_and_who_is_online(): void
    {
        $this->priya->update(['last_login' => now()->subMinutes(5)]);
        $custom = $this->group('Interview prep', 'Chat', [$this->priya->public_id, $this->arun->public_id]);

        $rows = collect($this->getJson(self::BASE."/groups/{$custom}")->assertOk()->json('members'))->keyBy('name');
        $this->assertTrue($rows['Priya']['removable']);
        $this->assertTrue($rows['Priya']['online']);
        $this->assertFalse($rows['Arun']['online']);
        $this->assertFalse($rows['Admin']['removable']);
    }

    public function test_editing_and_deleting_groups(): void
    {
        $custom = $this->group('Interview prep', 'Chat', [$this->priya->public_id]);

        $this->putJson(self::BASE."/groups/{$custom}", ['name' => 'Placement prep', 'members' => [$this->arun->public_id]])->assertOk()
            ->assertJsonPath('group.title', 'Placement prep')->assertJsonPath('group.members_total', 2);
        $this->putJson(self::BASE."/groups/{$custom}", ['members' => []])->assertUnprocessable();

        $floor = CommunityGroup::where('source', 'floor')->first() ?? collect($this->getJson(self::BASE.'/groups')->json('groups'))->firstWhere('source', 'floor');
        $floorId = is_array($floor) ? $floor['id'] : $floor->id;
        $this->deleteJson(self::BASE."/groups/{$floorId}")->assertUnprocessable();
        $courseId = collect($this->getJson(self::BASE.'/groups?source=course')->json('groups'))->first()['id'];
        $this->deleteJson(self::BASE."/groups/{$courseId}")->assertUnprocessable();
        $this->putJson(self::BASE."/groups/{$courseId}", ['name' => 'Renamed'])->assertUnprocessable();

        Sanctum::actingAs($this->priya);
        $this->deleteJson(self::BASE."/groups/{$custom}")->assertForbidden();

        Sanctum::actingAs($this->admin);
        $this->deleteJson(self::BASE."/groups/{$custom}")->assertOk();
        $this->assertNotContains('Placement prep', $this->titles());
        $this->postJson(self::BASE.'/groups', ['name' => 'Placement prep', 'kind' => 'Chat', 'members' => [$this->priya->public_id]])->assertCreated(); // the name is free again
    }

    public function test_the_creator_manages_their_own_group_but_not_others(): void
    {
        $member = User::factory()->role(Role::Member)->create();
        $mine = $this->group('Mine', 'Chat', [$this->priya->public_id], $member);
        $theirs = $this->group('Theirs', 'Chat', [$this->priya->public_id], $this->admin);

        Sanctum::actingAs($member);
        $this->postJson(self::BASE."/groups/{$mine}/members", ['members' => [$this->arun->public_id]])->assertOk();
        $this->postJson(self::BASE."/groups/{$theirs}/members", ['members' => [$this->arun->public_id]])->assertForbidden();
        $this->deleteJson(self::BASE."/groups/{$mine}")->assertOk();
    }

    public function test_personal_chats_are_private_to_their_two_people_even_from_staff(): void
    {
        Sanctum::actingAs($this->priya);
        $dm = $this->postJson(self::BASE.'/dm', ['user' => $this->arun->public_id])->assertOk()->assertJsonPath('group.source', 'dm')->json('group.id');
        $this->assertSame($dm, $this->postJson(self::BASE.'/dm', ['user' => $this->arun->public_id])->json('group.id')); // reused
        $this->postJson(self::BASE.'/dm', ['user' => $this->priya->public_id])->assertUnprocessable(); // not yourself

        $this->postJson(self::BASE."/groups/{$dm}/messages", ['body' => 'Hi Arun'])->assertCreated();
        Sanctum::actingAs($this->arun);
        $this->getJson(self::BASE."/groups/{$dm}/messages")->assertOk()->assertJsonCount(1, 'messages');
        $this->postJson(self::BASE."/groups/{$dm}/messages", ['body' => 'Hi Priya'])->assertCreated();

        Sanctum::actingAs($this->mentor);
        $this->getJson(self::BASE."/groups/{$dm}/messages")->assertForbidden();

        Sanctum::actingAs($this->admin);
        $this->getJson(self::BASE."/groups/{$dm}/messages")->assertForbidden(); // staff cannot read it either
        $this->postJson(self::BASE."/groups/{$dm}/messages", ['body' => 'Joining in'])->assertForbidden();
        $this->getJson(self::BASE.'/dms')->assertOk()->assertJsonCount(0, 'groups'); // and it is not in their list

        Sanctum::actingAs($this->arun);
        $list = $this->getJson(self::BASE.'/dms')->assertOk()->assertJsonCount(1, 'groups'); // the two people see it
        $list->assertJsonPath('groups.0.messages_total', 2);

        Sanctum::actingAs($this->mentor);
        $this->getJson(self::BASE.'/dms')->assertOk()->assertJsonCount(0, 'groups');
    }

    public function test_staff_review_one_person_across_every_group(): void
    {
        $chat = $this->group('Interview prep', 'Chat', [$this->priya->public_id]);
        Sanctum::actingAs($this->priya);
        $this->postJson(self::BASE."/groups/{$chat}/messages", ['body' => 'In the group'])->assertCreated();
        $floor = collect($this->getJson(self::BASE.'/groups?kind=Chat')->json('groups'))->firstWhere('source', 'floor')['id'];
        $this->postJson(self::BASE."/groups/{$floor}/messages", ['body' => 'On the floor'])->assertCreated();

        Sanctum::actingAs($this->mentor);
        $this->getJson(self::BASE.'/person?user='.urlencode($this->priya->public_id))->assertForbidden();

        Sanctum::actingAs($this->admin);
        $res = $this->getJson(self::BASE.'/person?user='.urlencode($this->priya->public_id))->assertOk()->assertJsonPath('person.name', 'Priya');
        $this->assertEqualsCanonicalizing(['In the group', 'On the floor'], collect($res->json('messages'))->pluck('body')->all());
        $this->assertContains('Interview prep', collect($res->json('groups'))->pluck('name')->all());
    }

    public function test_the_overview_counts_groups_and_who_is_signed_in(): void
    {
        $this->priya->update(['last_login' => now()->subMinutes(5)]);
        $this->arun->update(['last_login' => now()->subHours(3)]);
        $this->group('Interview prep');
        $this->group('Notices', 'Announcement');

        $o = $this->getJson(self::BASE.'/overview')->assertOk()->json('overview');
        $this->assertSame(['groups' => 3, 'course' => 1, 'custom' => 1], $o['chat']); // Common Floor, the course, the custom one
        $this->assertSame(['groups' => 3, 'course' => 1, 'custom' => 1], $o['announcement']); // All users, the course, the custom one
        $this->assertSame(1, $o['users_online']);
        $this->assertSame(User::count(), $o['users_total']);

        Sanctum::actingAs($this->priya);
        $this->getJson(self::BASE.'/overview')->assertForbidden();
    }


    public function test_messages_and_chat_list_changes_are_pushed_live(): void
    {
        Event::fake([CommunityMessagePosted::class, CommunityMessageRemoved::class, CommunityChatsChanged::class]);
        $id = $this->group('Interview prep', 'Chat', [$this->priya->public_id]);
        Event::assertDispatched(CommunityChatsChanged::class, fn ($e) => $e->userIds === [$this->priya->public_id]);

        $sent = $this->postJson(self::BASE."/groups/{$id}/messages", ['body' => 'Hello team'])->assertCreated()->json('message');
        Event::assertDispatched(CommunityMessagePosted::class, fn ($e) => $e->groupId === $id && $e->message['body'] === 'Hello team');

        $this->deleteJson(self::BASE."/groups/{$id}/messages/{$sent['id']}")->assertOk();
        Event::assertDispatched(CommunityMessageRemoved::class, fn ($e) => $e->groupId === $id && $e->messageId === $sent['id']);
    }

    public function test_only_people_who_can_read_a_group_may_listen_to_its_channel(): void
    {
        // The log driver lets everyone in, so authorise through the real (Reverb) driver.
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'k', 'broadcasting.connections.reverb.secret' => 's', 'broadcasting.connections.reverb.app_id' => '1']);
        require base_path('routes/channels.php'); // register the channels on the Reverb driver too
        $id = $this->group('Interview prep', 'Chat', [$this->priya->public_id]);
        $auth = function (User $u) use ($id) {
            $this->app['auth']->forgetGuards();

            return $this->withToken($u->createToken('t')->plainTextToken)->postJson('/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => "private-community.group.{$id}"]);
        };

        $auth($this->priya)->assertOk();
        $auth($this->arun)->assertForbidden();
    }
}
