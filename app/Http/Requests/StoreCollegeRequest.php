<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Enums\CollegeStatus;

class StoreCollegeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'code' => ['nullable', 'regex:/^[A-Z]{3}$/', Rule::unique('colleges', 'code')->ignore($this->route('college'))],
            'city' => ['nullable', 'string', 'max:100'],
            'principal_name' => ['nullable', 'string', 'max:150'],
            'mobile' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'string', 'email', 'max:150'],
            // Where students must be to report present; leave both empty for no location check.
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'geofence_radius_m' => ['nullable', 'integer', 'min:20', 'max:5000'],
            'status' => [
                'sometimes',
                Rule::enum(CollegeStatus::class),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'College name is required.',
            'code.regex' => 'College code must be exactly 3 letters.',
            'code.unique' => 'That college code is already in use.',
            'mobile.string' => 'Mobile number must be a string.',
            'mobile.max' => 'Mobile number must not exceed 20 characters.',
            'email.string' => 'Email must be a string.',
            'email.email' => 'Email must be a valid email address.',
            'email.max' => 'Email must not exceed 150 characters.',
            'status.enum' => 'Invalid college status.',
            'lat.required_with' => 'Give both the latitude and the longitude.',
            'lng.required_with' => 'Give both the latitude and the longitude.',
            'geofence_radius_m.min' => 'The radius must be at least 20 m.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'code' => strtoupper(trim((string) $this->input('code'))) ?: null,
            'mobile' => trim((string) $this->input('mobile')) ?: null,
            'email' => trim((string) $this->input('email')) ?: null,
        ]);
    }
}
