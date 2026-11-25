<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreQuizResultRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'assessment_id' => ['required', 'integer', 'exists:assessments,id'],
            'student_id' => ['required', 'integer', 'exists:users,id'],
            'marks' => ['required', 'integer', 'min:0'],
            'attempts' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
