<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PostCommunityMessageRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'body' => ['required_without:file', 'nullable', 'string', 'max:1000'],
            // An image or a document, like WhatsApp: up to 5 MB.
            'file' => ['nullable', 'file', 'max:5120', 'mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,zip'],
        ];
    }

    public function messages(): array
    {
        return ['body.required_without' => 'Type a message or attach a file.', 'file.max' => 'A file must be 5 MB or smaller.', 'file.mimes' => 'Attach an image, PDF, Office file, text or zip.', 'body.max' => 'Message is too long (1000 characters at most).'];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['body' => trim((string) $this->input('body')) ?: null]);
    }
}
