<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreQuestionGroupRequest extends FormRequest
{
    public function rules(): array
    {
        // A group belongs to quizzes or to assessments for good; the type is only read when creating.
        $type = $this->route('group')?->type ?? $this->input('type', 'quiz');

        return [
            'type' => ['sometimes', Rule::in(['quiz', 'assessment'])],
            'title' => ['required', 'string', 'max:100', Rule::unique('question_groups', 'title')->where('type', $type)->ignore($this->route('group'))],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'Title is required.',
            'title.unique' => 'A question group with that title already exists.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['title' => trim((string) $this->input('title'))]);
    }
}
