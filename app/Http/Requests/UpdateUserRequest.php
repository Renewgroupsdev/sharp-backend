<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Edit an account. Role never changes (the public id is built from it); status and password have their own endpoints. */
class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // UserController::update authorizes against the target user
    }

    public function rules(): array
    {
        /** @var User $target */
        $target = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:150'],
            'username' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9._-]+$/', Rule::unique('users', 'username')->ignore($target->id)],
            'college_id' => [Rule::requiredIf($target->role->requiresCollege()), 'nullable', 'integer', Rule::exists('colleges', 'id')],
            'org' => ['nullable', 'string', 'max:200'],
            'batch_id' => ['nullable', 'integer', Rule::exists('batches', 'id')],
            'mobile' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:150', Rule::unique('users', 'email')->ignore($target->id)],
            'specialist' => ['nullable', 'string', 'max:255'],
            'short_description' => ['nullable', 'string', 'max:255'],
            'biography' => ['nullable', 'string', 'max:5000'],
            'avatar' => ['nullable', 'string', 'max:450000'], // a data URL of a new picture
            'avatar_remove' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Full name is required.',
            'username.unique' => 'That username is already taken.',
            'email.unique' => 'That email is already used by another user.',
            'college_id.required' => 'A college must be selected for this role.',
            'college_id.exists' => 'College not found.',
            'batch_id.exists' => 'Batch not found.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $clean = fn ($v) => is_string($v) ? (trim($v) ?: null) : $v;

        $this->merge([
            'name' => trim((string) $this->input('name')),
            'username' => strtolower(trim((string) $this->input('username'))),
            'org' => $clean($this->input('org')),
            'batch_id' => $clean($this->input('batch_id')),
            'mobile' => $clean($this->input('mobile')),
            'email' => $clean($this->input('email')),
            'specialist' => \App\Services\SpecialistService::normalize($this->input('specialist')),
            'short_description' => $clean($this->input('short_description')),
            'biography' => \App\Support\SafeHtml::clean($this->input('biography')), // rich text: formatting kept, scripts and attributes dropped
        ]);
    }
}
