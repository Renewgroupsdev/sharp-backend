<?php

namespace App\Http\Requests;

use App\Support\Uploads;
use Illuminate\Foundation\Http\FormRequest;

class StoreAssignmentSubmissionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'content' => ['nullable', 'string', 'max:50000', 'required_without:files'],
            'files' => ['nullable', 'array', 'max:5', 'required_without:content'],
            'files.*' => Uploads::documentRules(),
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('content'))) {
            $this->merge(['content' => trim($this->input('content'))]);
        }
    }
}
