<?php

namespace App\Http\Requests;

use App\Enums\QuestionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreQuestionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'group_id' => ['required', 'integer', 'exists:question_groups,id'],
            'type' => ['required', Rule::enum(QuestionType::class)],
            'marks' => ['required', 'integer', 'min:1', 'max:100'],
            'text' => ['required', 'string', 'max:5000'],
            'image' => ['nullable', 'string'],
            'data' => ['nullable', 'array'],
            'explanation' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'group_id.required' => 'Choose a question group.',
            'text.required' => 'Question text is required.',
            'type.enum' => 'Invalid question type.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['text' => trim((string) $this->input('text'))]);
    }
}
