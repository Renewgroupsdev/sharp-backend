<?php

namespace App\Http\Requests;

use App\Models\CertificateSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Details of a certificate that is not issued yet. Anything left out is left as it is; null clears it. */
class UpdateCertificateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'issue_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'score' => ['sometimes', 'nullable', 'integer', 'between:0,100'],
            'min_attendance' => ['sometimes', 'nullable', 'integer', 'between:0,100'],
            'design' => ['sometimes', 'nullable', 'array'],
            'design.show_profile' => ['sometimes', 'boolean'],
            'design.show_qr' => ['sometimes', 'boolean'],
            'design.signature_image' => ['nullable', 'string', 'max:200000', 'regex:/^data:image\/(png|jpeg);base64,[A-Za-z0-9+\/=]+$/'],
            'design.template' => ['required_with:design', Rule::in(CertificateSetting::TEMPLATES)],
            'design.type' => ['required_with:design', Rule::in(CertificateSetting::TYPES)],
            'design.title' => ['required_with:design', 'string', 'max:120'],
            'design.body' => ['required_with:design', 'string', 'max:1000'],
            'design.signature_name' => ['nullable', 'string', 'max:80'],
            'design.signature_role' => ['nullable', 'string', 'max:80'],
        ];
    }

    public function messages(): array
    {
        return ['issue_date.before_or_equal' => 'The issue date cannot be in the future.'];
    }
}
