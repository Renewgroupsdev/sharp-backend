<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewCertificateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['approved', 'returned'])],
            'note' => [Rule::requiredIf($this->input('decision') === 'returned'), 'nullable', 'string', 'min:3', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return ['note.required' => 'Say why you are returning it.'];
    }
}
