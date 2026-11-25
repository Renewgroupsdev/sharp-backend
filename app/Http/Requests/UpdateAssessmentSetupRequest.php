<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAssessmentSetupRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'per_question' => ['required', 'integer', 'min:0', 'max:600'],
            'question_count' => ['nullable', 'integer', 'min:0', 'max:500'],
            'min_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'review' => ['required', 'boolean'],
            'random' => ['required', 'boolean'],
            'multi' => ['required', 'boolean'],
            'same_page' => ['required', 'boolean'],
            'explanations' => ['required', 'boolean'],
            'answer_sheets' => ['required', 'boolean'],
            'score_report' => ['required', 'boolean'],
            'total_correct' => ['required', 'boolean'],
            'answer_sheet' => ['required', Rule::in(['correct', 'wrong', 'none'])],
            'group_ids' => ['nullable', 'array'],
            'group_ids.*' => ['integer', 'distinct', 'exists:question_groups,id'],
        ];
    }
}
