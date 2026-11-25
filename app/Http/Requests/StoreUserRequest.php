<?php

namespace App\Http\Requests;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('create', [User::class, Role::tryFrom((string) $this->input('role'))]);

        return true;
    }

    public function rules(): array
    {
        $role = Role::tryFrom((string) $this->input('role'));

        return [
            'role' => ['required', Rule::enum(Role::class)],
            'name' => ['required', 'string', 'max:150'],
            'username' => ['nullable', 'string', 'max:60', 'regex:/^[a-z0-9._-]+$/', Rule::unique('users', 'username')],
            'temp_password' => ['nullable', 'string', 'min:6'],
            'college_id' => [Rule::requiredIf($role?->requiresCollege() ?? false), 'nullable', 'integer', Rule::exists('colleges', 'id')],
            'org' => ['nullable', 'string', 'max:200'],
            'batch_id' => ['nullable', 'integer', Rule::exists('batches', 'id')],
            'mobile' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:150'],
            'avatar' => ['nullable', 'string', 'max:450000'],
        ];
    }

    public function messages(): array
    {
        return [
            'role.enum' => 'Unknown role.',
            'name.required' => 'Full name is required.',
            'username.unique' => 'That username is already taken.',
            'temp_password.min' => 'Temporary password must be at least 6 characters.',
            'college_id.required' => 'A college must be selected for this role.',
            'college_id.exists' => 'College not found.',
            'batch_id.exists' => 'Batch not found.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $clean = fn ($v) => is_string($v) ? (trim($v) ?: null) : $v;

        $this->merge([
            'role' => strtolower(trim((string) $this->input('role'))),
            'username' => ($u = $clean($this->input('username'))) ? strtolower($u) : null,
            'name' => trim((string) $this->input('name')),
            'org' => $clean($this->input('org')),
            'batch_id' => $clean($this->input('batch_id')),
            'mobile' => $clean($this->input('mobile')),
            'email' => $clean($this->input('email')),
        ]);
    }

    /** Role enum for the validated input. */
    public function role(): Role
    {
        return Role::from($this->validated('role'));
    }
}
