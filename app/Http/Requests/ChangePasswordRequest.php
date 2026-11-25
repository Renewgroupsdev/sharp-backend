<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;

class ChangePasswordRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'new_password' => ['required', 'string', 'min:8'],
            'current_password' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return ['new_password.min' => 'New password must be at least 8 characters.'];
    }

    /** A forced first-sign-in change skips the current-password check. */
    public function after(): array
    {
        return [function (Validator $validator) {
            $user = $this->user();

            if (! $user->must_change_password && ! Hash::check((string) $this->input('current_password'), $user->password)) {
                $validator->errors()->add('current_password', 'Current password is incorrect.');
            }
        }];
    }
}
