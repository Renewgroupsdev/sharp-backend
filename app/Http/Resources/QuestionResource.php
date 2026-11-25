<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Question */
class QuestionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'group_id' => $this->question_group_id,
            'group' => $this->whenLoaded('group', fn () => $this->group->title),
            'type' => $this->type,
            'marks' => $this->marks,
            'text' => $this->text,
            'image' => $this->image,
            'data' => $this->data ?? (object) [],
            'explanation' => $this->explanation,
        ];
    }
}
