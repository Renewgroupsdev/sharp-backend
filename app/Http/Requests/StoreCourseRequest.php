<?php

namespace App\Http\Requests;

use App\Enums\CourseLevel;
use App\Enums\CourseMode;
use App\Enums\CourseScope;
use App\Enums\CourseStatus;
use App\Http\Requests\Concerns\ChapterRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Create / update a course; `chapters` is optional and, when present, replaces the whole curriculum. */
class StoreCourseRequest extends FormRequest
{
    use ChapterRules;

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200', Rule::unique('courses', 'title')->ignore($this->route('course'))->whereNull('deleted_at')],
            'course_category_id' => ['required', 'integer', 'exists:course_categories,id'],
            'batch_ids' => ['nullable', 'array'],
            'batch_ids.*' => ['integer', 'distinct', 'exists:batches,id'],
            'hours' => ['required', 'integer', 'min:1', 'max:2000'],
            'mentors' => ['nullable', 'array', 'max:20'],
            'mentors.*' => ['string', 'max:150', 'distinct'],
            'mode' => ['sometimes', Rule::enum(CourseMode::class)],
            'level' => ['sometimes', Rule::enum(CourseLevel::class)],
            'scope' => ['sometimes', Rule::enum(CourseScope::class)],
            'status' => ['sometimes', Rule::enum(CourseStatus::class)],
            'description' => ['nullable', 'string', 'max:5000'],
            'outcomes' => ['nullable', 'string', 'max:5000'],
            'requirements' => ['nullable', 'string', 'max:5000'],
            'thumbnail' => ['nullable', 'string', 'max:450000'], // ~300 KB image as a data URL
        ] + $this->chapterRules();
    }

    public function messages(): array
    {
        return [
            'title.required' => 'Course title is required.',
            'title.unique' => 'A course with this title already exists.',
            'course_category_id.exists' => 'Choose a valid category.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['title' => trim((string) $this->input('title'))]);
    }
}
