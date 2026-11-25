<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** The student's answers to a quiz attempt. An unanswered question is simply left out (or sent without an answer). */
class SubmitQuizRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'attempt_id' => ['required', 'integer'],
            'answers' => ['present', 'array', 'max:500'],
            'answers.*.question_id' => ['required', 'integer'],
            'answers.*.answer' => ['nullable'],
        ];
    }

    public function messages(): array
    {
        return ['attempt_id.required' => 'Start the quiz before submitting it.'];
    }
}
