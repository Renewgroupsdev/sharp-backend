<?php

namespace App\Services;

use App\Models\NotificationRule;
use App\Models\User;
use App\Support\NotificationCatalog;
use Illuminate\Support\Collection;

/** The rules behind Notification Management: one row per catalog event, created on first read, edited by staff. */
class NotificationRuleService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @return Collection<int, NotificationRule> in catalog order */
    public function all(): Collection
    {
        $existing = NotificationRule::query()->get()->keyBy('event');

        return collect(NotificationCatalog::EVENTS)->map(fn (array $meta, string $event) => $existing->get($event)
            ?? NotificationRule::create(['event' => $event, 'enabled' => true, 'title' => $meta['title'], 'body' => $meta['body']]))->values();
    }

    /** The rule of one event (created from the catalog on first use); 404 for an event that does not exist. */
    public function find(string $event): NotificationRule
    {
        abort_unless(isset(NotificationCatalog::EVENTS[$event]), 404, 'Unknown notification event.');
        $meta = NotificationCatalog::get($event);

        return NotificationRule::firstOrCreate(['event' => $event], ['enabled' => true, 'title' => $meta['title'], 'body' => $meta['body']]);
    }

    /** The wording to send for an event, or null when staff switched the rule off. */
    public function resolve(string $event): ?array
    {
        $meta = NotificationCatalog::get($event);
        $rule = NotificationRule::query()->where('event', $event)->first();

        if (! $rule) {
            return ['title' => $meta['title'], 'body' => $meta['body']];
        }

        return $rule->enabled ? ['title' => $rule->title, 'body' => $rule->body] : null;
    }

    public function update(User $actor, NotificationRule $rule, array $data): NotificationRule
    {
        $rule->update(array_intersect_key($data, array_flip(['enabled', 'title', 'body'])));
        $label = $rule->meta()['label'];
        $this->audit->record($actor, array_key_exists('enabled', $data) && count($data) === 1
            ? ($rule->enabled ? 'Enabled a notification rule' : 'Disabled a notification rule')
            : 'Edited a notification rule', $rule->event, $label);

        return $rule;
    }

    /** Back to the catalog's own wording, switched on. */
    public function reset(User $actor, NotificationRule $rule): NotificationRule
    {
        $meta = $rule->meta();
        $rule->update(['enabled' => true, 'title' => $meta['title'], 'body' => $meta['body']]);
        $this->audit->record($actor, 'Reset a notification rule', $rule->event, $meta['label']);

        return $rule;
    }
}
