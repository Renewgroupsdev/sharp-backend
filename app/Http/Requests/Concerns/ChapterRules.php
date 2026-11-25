<?php

namespace App\Http\Requests\Concerns;

use App\Enums\LessonHost;
use App\Enums\LessonPrivacy;
use Illuminate\Validation\Rule;

/** Validation for a course's chapter / lesson tree; shared by the course form and the curriculum-only endpoint. */
trait ChapterRules
{
    protected function chapterRules(): array
    {
        $links = implode(',', LessonHost::linkHosts());
        $editor = LessonHost::Editor->value;

        return [
            'chapters' => ['nullable', 'array', 'max:100'],
            'chapters.*.id' => ['nullable', 'integer'],
            'chapters.*.name' => ['required', 'string', 'max:150'],
            'chapters.*.description' => ['nullable', 'string', 'max:1000'],
            'chapters.*.status' => ['sometimes', Rule::in(['Draft', 'Published'])],
            'chapters.*.lessons' => ['nullable', 'array', 'max:200'],
            'chapters.*.lessons.*.id' => ['nullable', 'integer'],
            'chapters.*.lessons.*.name' => ['required', 'string', 'max:150'],
            'chapters.*.lessons.*.duration_minutes' => ['required', 'integer', 'min:1', 'max:600'],
            'chapters.*.lessons.*.host' => ['required', Rule::enum(LessonHost::class)],
            'chapters.*.lessons.*.url' => ["required_if:chapters.*.lessons.*.host,{$links}", 'nullable', 'url', 'max:500'],
            'chapters.*.lessons.*.content' => ["required_if:chapters.*.lessons.*.host,{$editor}", 'nullable', 'string'],
            'chapters.*.lessons.*.privacy' => ['required', Rule::enum(LessonPrivacy::class)],
            'chapters.*.lessons.*.description' => ['nullable', 'string', 'max:2000'],
            'chapters.*.lessons.*.file_name' => ['nullable', 'string', 'max:255'],
            'chapters.*.lessons.*.file_size' => ['nullable', 'string', 'max:30'],
        ];
    }
}
