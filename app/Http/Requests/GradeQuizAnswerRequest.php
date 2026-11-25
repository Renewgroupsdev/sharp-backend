<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GradeQuizAnswerRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'question_id' => ['required', 'integer'],
            'marks' => ['required', 'integer', 'min:0', 'max:1000'],
            'feedback' => ['nullable', 'string', 'max:500'],
        ];
    }
}
