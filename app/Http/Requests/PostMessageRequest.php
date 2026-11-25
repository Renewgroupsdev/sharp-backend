<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PostMessageRequest extends FormRequest
{
    public function rules(): array
    {
        return ['message' => ['required', 'string', 'max:1000']];
    }

    public function messages(): array
    {
        return [
            'message.required' => 'Message cannot be empty.',
            'message.max' => 'Message is too long.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['message' => trim((string) $this->input('message'))]);
    }
}
