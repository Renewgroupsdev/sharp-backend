<?php

namespace App\Http\Requests;

use App\Models\Certificate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Cancelling an issued certificate needs a reason: one from the list, or Other with a note. */
class CancelCertificateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['required', Rule::in(Certificate::CANCEL_REASONS)],
            'note' => [Rule::requiredIf($this->input('reason') === Certificate::OTHER_REASON), 'nullable', 'string', 'min:3', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Choose why the certificate is being cancelled.',
            'reason.in' => 'Choose one of the listed reasons.',
            'note.required' => 'Write the reason when you choose Other.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['note' => trim((string) $this->input('note')) ?: null]);
    }
}
