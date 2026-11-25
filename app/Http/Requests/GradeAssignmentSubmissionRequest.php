<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GradeAssignmentSubmissionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'marks_obtained' => ['required', 'numeric', 'min:0', 'max:10000'],
            'feedback' => ['nullable', 'string', 'max:10000'],
            'status' => ['sometimes', 'in:graded,returned'],
        ];
    }
}
