<?php

namespace App\Services;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Events\NotificationsPushed;
use App\Models\Notification;
use App\Models\User;
use App\Support\NotificationCatalog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The in-app inbox. Services fire a catalog event with `notify` / `notifyBatch`: the rule behind it decides whether it is
 * sent and with what wording. Every stored notification is pushed to the recipients' open screens at once over WebSockets
 * (the push never blocks or fails the action; the inbox row is the source of truth). Clients read their own inbox and mark
 * items read.
 */
class NotificationService
{
    private const PUSH_CHUNK = 100;

    public function __construct(private readonly NotificationRuleService $rules, private readonly AuditLogger $audit) {}

    /**
     * Fire a catalog event for some people. Does nothing when staff switched the rule off.
     *
     * @param  int|list<int>  $userIds
     * @param  array<string, scalar|null>  $vars  fills the `{placeholders}` of the rule's wording
     * @param  array<string, mixed>  $data  what a client needs to open the thing (course id, url...)
     */
    public function notify(string $event, int|array $userIds, array $vars = [], array $data = []): void
    {
        $wording = $this->rules->resolve($event);
        if ($wording === null) {
            return;
        }

        $this->toUsers((array) $userIds, NotificationCatalog::get($event)['type'],
            NotificationCatalog::render($wording['title'], $vars), NotificationCatalog::render($wording['body'], $vars), $data);
    }

    /**
     * Fire a catalog event for the programme office (Super Admin, Admin, OB) and, when a batch is given, that batch's mentors.
     *
     * @param  array<string, scalar|null>  $vars
     * @param  array<string, mixed>  $data
     */
    public function notifyStaff(string $event, ?int $batchId, array $vars = [], array $data = [], ?int $exceptUserId = null): void
    {
        $ids = User::query()
            ->where('status', '<>', UserStatus::Suspended->value)
            ->when($exceptUserId, fn ($q) => $q->where('id', '<>', $exceptUserId))
            ->where(fn ($q) => $q->whereIn('role', array_map(fn (Role $r) => $r->value, Role::staff()))
                ->when($batchId, fn ($w) => $w->orWhere(fn ($m) => $m->where('role', Role::Mentor->value)->where('batch_id', $batchId))))
            ->pluck('id')->all();

        if ($ids) {
            $this->notify($event, $ids, $vars, $data);
        }
    }

    /** @param  list<Role>  $roles */
    public function notifyBatch(string $event, int $batchId, array $roles, array $vars = [], array $data = []): void
    {
        $ids = $this->batchPeople($batchId, $roles);
        if ($ids) {
            $this->notify($event, $ids, $vars, $data);
        }
    }

    /**
     * One row per recipient, inserted in chunks, then pushed live. This is the low-level writer; prefer `notify` so the
     * rule is honoured.
     *
     * @param  list<int>  $userIds
     * @param  array<string, mixed>  $data  what a client needs to open the thing (course id, url...)
     */
    public function toUsers(array $userIds, string $type, string $title, ?string $body = null, array $data = []): void
    {
        $ids = array_values(array_unique($userIds));
        if (! $ids) {
            return;
        }

        $now = now();
        $json = $data ? json_encode($data) : null;
        foreach (array_chunk($ids, 500) as $chunk) {
            Notification::insert(array_map(fn (int $id) => [
                'user_id' => $id, 'type' => $type, 'title' => $title, 'body' => $body, 'data' => $json, 'created_at' => $now,
            ], $chunk));
        }

        $this->push($ids, ['type' => $type, 'title' => $title, 'body' => (string) $body, 'url' => (string) ($data['url'] ?? '')]);
    }

    /** @param  list<Role>  $roles */
    public function toBatch(int $batchId, array $roles, string $type, string $title, ?string $body = null, array $data = []): void
    {
        $ids = $this->batchPeople($batchId, $roles);
        if ($ids) {
            $this->toUsers($ids, $type, $title, $body, $data);
        }
    }

    /**
     * A message staff write by hand. Goes to everyone with one of `$roles` (limited to one batch when given) plus the
     * people picked by public id. Suspended users and the sender are left out. Returns how many people got it.
     *
     * @param  list<string>  $roles  Role values
     * @param  list<string>  $publicIds
     */
    public function compose(User $actor, array $roles, ?int $batchId, array $publicIds, string $title, ?string $body): int
    {
        $ids = User::query()
            ->where('status', '<>', UserStatus::Suspended->value)
            ->where('id', '<>', $actor->id)
            ->where(fn ($q) => $q
                ->when($roles, fn ($r) => $r->orWhere(fn ($w) => $w->whereIn('role', $roles)->when($batchId, fn ($b) => $b->where('batch_id', $batchId))))
                ->when($publicIds, fn ($r) => $r->orWhereIn('public_id', $publicIds)))
            ->pluck('id')->all();

        $this->toUsers($ids, 'message', $title, $body, ['url' => '', 'from' => $actor->name]);
        $this->audit->record($actor, 'Sent a notification', (string) count($ids), $title);

        return count($ids);
    }

    /** @return Collection<int, Notification> newest first */
    public function inbox(User $user, int $limit = 30): Collection
    {
        return Notification::where('user_id', $user->id)->orderByDesc('id')->limit($limit)->get();
    }

    public function unreadCount(User $user): int
    {
        return Notification::where('user_id', $user->id)->whereNull('read_at')->count();
    }

    public function markRead(User $user, Notification $notification): void
    {
        abort_unless($notification->user_id === $user->id, 404);

        if (! $notification->read_at) {
            $notification->update(['read_at' => now()]);
        }
    }

    public function markAllRead(User $user): void
    {
        Notification::where('user_id', $user->id)->whereNull('read_at')->update(['read_at' => now()]);
    }

    /** @param  list<Role>  $roles
     * @return list<int> */
    private function batchPeople(int $batchId, array $roles): array
    {
        return User::where('batch_id', $batchId)->whereIn('role', array_map(fn (Role $r) => $r->value, $roles))->pluck('id')->all();
    }

    /**
     * Tells the recipients' open screens. Failure is logged and swallowed: the notification is already stored.
     *
     * @param  list<int>  $userIds
     * @param  array{type: string, title: string, body: string, url: string}  $payload
     */
    private function push(array $userIds, array $payload): void
    {
        try {
            $publicIds = User::query()->whereIn('id', $userIds)->pluck('public_id')->map(fn ($p) => (string) $p)->all();
            foreach (array_chunk($publicIds, self::PUSH_CHUNK) as $chunk) {
                event(new NotificationsPushed($chunk, $payload));
            }
        } catch (Throwable $e) {
            Log::warning('Notification real-time push failed: '.$e->getMessage());
        }
    }
}
