<?php

namespace App\Http\Resources;

use App\Enums\LessonPrivacy;
use App\Enums\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Course */
class CourseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code(),
            'title' => $this->title,
            'category_id' => $this->course_category_id,
            'category' => $this->whenLoaded('category', fn () => $this->category->name),
            'batches' => $this->whenLoaded('batches', fn () => $this->batches->map(fn ($b) => ['id' => $b->id, 'name' => $b->name])->values()),
            'hours' => $this->hours,
            'mentors' => $this->mentors ?? [],
            'mode' => $this->mode,
            'level' => $this->level,
            'scope' => $this->scope,
            'status' => $this->status,
            'description' => $this->description,
            'outcomes' => $this->outcomes,
            'requirements' => $this->requirements,
            'thumbnail' => $this->thumbnail,
            'deleted_at' => $this->deleted_at,
            'chapters' => $this->whenLoaded('chapters', fn () => $this->chapters->when($this->hidesDraftModules($request), fn ($c) => $c->where('status', 'Published'))->map(fn ($chapter) => [
                'id' => $chapter->id,
                'name' => $chapter->name,
                'description' => $chapter->description,
                'status' => $chapter->status,
                'lessons' => $chapter->lessons->when($this->hidesDraftModules($request), fn ($l) => $l->filter(fn ($x) => $x->privacy !== LessonPrivacy::Private))->map(fn ($lesson) => [
                    'id' => $lesson->id,
                    'name' => $lesson->name,
                    'duration_minutes' => $lesson->duration_minutes,
                    'host' => $lesson->host,
                    'url' => $lesson->url,
                    'content' => $lesson->content,
                    'privacy' => $lesson->privacy,
                    'description' => $lesson->description,
                    'file_name' => $lesson->file_name,
                    'file_size' => $lesson->file_size,
                ])->values(),
            ])->values()),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /** Draft modules and private lessons are for staff only. */
    private function hidesDraftModules(Request $request): bool
    {
        return ! $request->user()?->hasRole(...Role::staff());
    }
}
