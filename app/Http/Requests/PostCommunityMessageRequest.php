<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PostCommunityMessageRequest extends FormRequest
{
    public function rules(): array
    {
        return ['body' => ['required', 'string', 'max:1000']];
    }

    public function messages(): array
    {
        return ['body.required' => 'Message cannot be empty.', 'body.max' => 'Message is too long (1000 characters at most).'];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['body' => trim((string) $this->input('body'))]);
    }
}
