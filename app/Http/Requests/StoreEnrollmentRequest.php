<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEnrollmentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'course_id' => ['required', 'integer', Rule::exists('courses', 'id')->whereNull('deleted_at')],
            'batch_ids' => ['required', 'array', 'min:1'],
            'batch_ids.*' => ['integer', 'distinct', 'exists:batches,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'course_id.required' => 'Choose a course.',
            'batch_ids.required' => 'Choose at least one batch.',
            'batch_ids.min' => 'Choose at least one batch.',
        ];
    }
}
