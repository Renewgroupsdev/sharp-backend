<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\CommunityMembersRequest;
use App\Http\Requests\PostCommunityMessageRequest;
use App\Http\Requests\StoreCommunityGroupRequest;
use App\Http\Requests\UpdateCommunityGroupRequest;
use App\Http\Resources\CommunityGroupResource;
use App\Http\Resources\CommunityMemberResource;
use App\Http\Resources\CommunityMessageResource;
use App\Models\CommunityGroup;
use App\Models\User;
use App\Services\CommunityAccess;
use App\Services\CommunityGroupService;
use App\Services\CommunityMessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * SHARP Community: groups, their people and their messages. Who may do what is decided in CommunityAccess (reading and
 * posting) and by the route groups (creating, removing messages, the staff-only review screens).
 */
class CommunityController extends ApiController
{
    public function __construct(
        private readonly CommunityGroupService $groups,
        private readonly CommunityMessageService $messages,
        private readonly CommunityAccess $access,
        private readonly \App\Services\CommunityChatStateService $states,
    ) {}

    public function overview(): JsonResponse
    {
        return $this->ok(['overview' => $this->groups->overview()]);
    }

    /** Staff: every group. Anyone else: the groups they belong to. */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'kind' => ['nullable', Rule::in(CommunityGroup::KINDS)],
            'source' => ['nullable', Rule::in([CommunityGroup::FLOOR, CommunityGroup::COURSE, CommunityGroup::CUSTOM])],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        return $this->ok(['groups' => CommunityGroupResource::collection($this->groups->list($request->user(), $filters))]);
    }

    /** A group with the people in it. */
    public function show(Request $request, CommunityGroup $group): JsonResponse
    {
        $found = $this->groups->find($request->user(), $group);

        return $this->ok([
            'group' => new CommunityGroupResource($found),
            'members' => CommunityMemberResource::collection($this->groups->memberList($found)),
        ]);
    }

    public function store(StoreCommunityGroupRequest $request): JsonResponse
    {
        $data = $request->validated();
        $group = $this->groups->create($request->user(), $data['name'], $data['kind'], $data['members']);

        return $this->ok(['group' => new CommunityGroupResource($group), 'members' => CommunityMemberResource::collection($this->groups->memberList($group))], 201);
    }

    public function update(UpdateCommunityGroupRequest $request, CommunityGroup $group): JsonResponse
    {
        $data = $request->validated();

        return $this->withMembers($this->groups->update($request->user(), $group, $data['name'] ?? null, $data['members'] ?? null));
    }

    public function addMembers(CommunityMembersRequest $request, CommunityGroup $group): JsonResponse
    {
        return $this->withMembers($this->groups->addMembers($request->user(), $group, $request->validated('members')));
    }

    /** POST, not DELETE: a public id contains slashes, which do not travel well in a URL path. */
    public function removeMember(Request $request, CommunityGroup $group): JsonResponse
    {
        $member = $request->validate(['member' => ['required', 'string', 'max:60']])['member'];

        return $this->withMembers($this->groups->removeMember($request->user(), $group, $member));
    }

    public function destroy(Request $request, CommunityGroup $group): JsonResponse
    {
        $this->groups->delete($request->user(), $group);

        return $this->ok(['message' => 'Group deleted.']);
    }

    public function messages(Request $request, CommunityGroup $group): JsonResponse
    {
        $list = $this->messages->list($request->user(), $group);

        return $this->ok([
            'messages' => CommunityMessageResource::collection($list),
            // How far this person had read before opening, so the chat can open at the first unread message.
            'last_read_message_id' => $this->states->lastRead($request->user(), $group),
            'pinned_messages' => CommunityMessageResource::collection($this->messages->pinned($request->user(), $group)),
            'can_pin' => $this->access->canPin($request->user(), $group),
        ]);
    }

    /** Pins or unpins a message for everyone in the chat; answers with the chat's pinned messages. */
    public function pinMessage(Request $request, CommunityGroup $group, int $message): JsonResponse
    {
        $pinned = $request->validate(['pinned' => ['required', 'boolean']])['pinned'];

        return $this->ok(['pinned_messages' => CommunityMessageResource::collection($this->messages->pin($request->user(), $group, $message, $pinned))]);
    }

    /** Marks the chat read up to a message (or its newest one). */
    public function read(Request $request, CommunityGroup $group): JsonResponse
    {
        $upTo = $request->validate(['message_id' => ['nullable', 'integer', 'min:0']])['message_id'] ?? null;

        return $this->ok(['last_read_message_id' => $this->states->markRead($request->user(), $group, $upTo)]);
    }

    /** Pins or unpins the chat in this person's own list. */
    public function pin(Request $request, CommunityGroup $group): JsonResponse
    {
        $pinned = $request->validate(['pinned' => ['required', 'boolean']])['pinned'];
        $this->states->pin($request->user(), $group, $pinned);

        return $this->ok(['pinned' => $pinned]);
    }

    public function post(PostCommunityMessageRequest $request, CommunityGroup $group): JsonResponse
    {
        return $this->ok(['message' => new CommunityMessageResource($this->messages->post($request->user(), $group, $request->validated('body')))], 201);
    }

    public function removeMessage(Request $request, CommunityGroup $group, int $message): JsonResponse
    {
        $this->messages->remove($request->user(), $group, $message);

        return $this->ok(['message' => 'Message removed.']);
    }

    /** Starts (or finds) the personal chat between the caller and another person. */
    public function openDm(Request $request): JsonResponse
    {
        $other = $request->validate(['user' => ['required', 'string', 'max:60']])['user'];

        return $this->ok(['group' => new CommunityGroupResource($this->groups->openDm($request->user(), $other))]);
    }

    /** Staff review every personal chat; anyone else sees their own. */
    public function dms(Request $request): JsonResponse
    {
        return $this->ok(['groups' => CommunityGroupResource::collection($this->groups->dms($request->user()))]);
    }

    /** Staff: one person's groups and everything they have written. `user` is a public id, which contains slashes, so it is a query parameter. */
    public function person(Request $request): JsonResponse
    {
        $publicId = $request->validate(['user' => ['required', 'string', 'max:60']])['user'];
        $summary = $this->groups->person($publicId);
        /** @var User $user */
        $user = $summary['user'];

        return $this->ok([
            'person' => [
                'user_id' => $user->public_id, 'name' => $user->name, 'role' => $user->role, 'org' => $user->org,
                'initials' => $user->avatar_initials, 'color' => $user->avatar_color, 'online' => $summary['online'],
                'last_login' => $user->last_login,
                'last_seen_at' => ($user->last_seen_at ?? $user->last_login)?->toISOString(),
            ],
            'groups' => $summary['groups']->map(fn (CommunityGroup $g) => ['id' => $g->id, 'name' => $g->name, 'kind' => $g->kind, 'source' => $g->source])->values(),
            'messages' => CommunityMessageResource::collection($this->messages->byUser($user)),
        ]);
    }

    private function withMembers(CommunityGroup $group): JsonResponse
    {
        return $this->ok(['group' => new CommunityGroupResource($group), 'members' => CommunityMemberResource::collection($this->groups->memberList($group))]);
    }
}
