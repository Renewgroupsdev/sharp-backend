<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveLearningProgressRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'student_id' => ['required', 'string', Rule::exists('users', 'public_id')],
            'course_id' => ['required', 'integer', Rule::exists('courses', 'id')->whereNull('deleted_at')],
            'progress' => ['required', 'integer', 'min:0', 'max:100'],
            'materials_completed' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'last_accessed_at' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'progress.max' => 'Progress cannot be more than 100%.',
            'progress.min' => 'Progress cannot be less than 0%.',
        ];
    }
}
