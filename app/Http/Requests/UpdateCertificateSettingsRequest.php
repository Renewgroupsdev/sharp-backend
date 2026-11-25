<?php

namespace App\Http\Requests;

use App\Models\CertificateSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCertificateSettingsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'template' => ['required', Rule::in(CertificateSetting::TEMPLATES)],
            'type' => ['required', Rule::in(CertificateSetting::TYPES)],
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:1000'],
            'prefix' => ['required', 'string', 'max:12', 'regex:/^[A-Za-z0-9]+(-[A-Za-z0-9]+)*$/'],
            'min_attendance' => ['required', 'integer', 'between:0,100'],
            'min_score' => ['required', 'integer', 'between:0,100'],
            'show_profile' => ['required', 'boolean'],
            'show_qr' => ['required', 'boolean'],
            'signature_name' => ['nullable', 'string', 'max:80'],
            'signature_role' => ['nullable', 'string', 'max:80'],
            // PNG / JPEG only (an SVG could carry script); about 150 KB once encoded.
            'signature_image' => ['nullable', 'string', 'max:200000', 'regex:/^data:image\/(png|jpeg);base64,[A-Za-z0-9+\/=]+$/'],
            'remove_signature_image' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'prefix.regex' => 'The prefix may contain letters, numbers and dashes only.',
            'signature_image.regex' => 'The signature must be a PNG or JPEG image.',
            'signature_image.max' => 'The signature image is too large (150 KB at most).',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['prefix' => strtoupper(trim((string) $this->input('prefix')))]);
    }
}
