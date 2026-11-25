<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\NotificationRule */
class NotificationRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $meta = $this->meta();

        return [
            'event' => $this->event,
            'label' => $meta['label'],
            'audience' => $meta['audience'],
            'channel' => 'In-app',
            'placeholders' => $meta['vars'],
            'enabled' => $this->enabled,
            'title' => $this->title,
            'body' => $this->body,
            'default_title' => $meta['title'],
            'default_body' => $meta['body'],
            'updated_at' => $this->updated_at,
        ];
    }
}
