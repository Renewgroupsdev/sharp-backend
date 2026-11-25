<?php

namespace App\Http\Requests;

use App\Enums\AssessmentStatus;
use App\Enums\AssessmentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAssessmentRequest extends FormRequest
{
    public function rules(): array
    {
        $quiz = in_array($this->input('type'), [AssessmentType::Quiz->value, AssessmentType::Assessment->value], true);

        return [
            'type' => ['required', Rule::enum(AssessmentType::class)],
            'title' => ['required', 'string', 'max:120'],
            'course' => ['required', 'string', 'max:150'],
            'module' => ['nullable', 'string', 'max:150'],
            'topic' => ['nullable', 'string', 'max:150'],
            'status' => ['sometimes', Rule::enum(AssessmentStatus::class)],
            // Quizzes and assessments take their marks from the question groups; the rest are marked out of `max_marks`.
            'due_date' => [$quiz ? 'nullable' : 'required', 'date'],
            'max_marks' => [$quiz ? 'nullable' : 'required', 'integer', 'min:1', 'max:10000'],
            'min_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'description' => ['nullable', 'string', 'max:5000'],
            'group_ids' => ['nullable', 'array'],
            'group_ids.*' => ['integer', 'distinct', 'exists:question_groups,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'Title is required.',
            'due_date.required' => 'Submit date is required for assignments.',
            'max_marks.required' => 'Marks are required for assignments.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['title' => trim((string) $this->input('title'))]);
    }
}
