<?php

namespace App\Http\Requests;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A message staff write by hand: who gets it (roles, optionally one batch, and/or named people) and the text. */
class SendNotificationRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'roles' => ['sometimes', 'array'],
            'roles.*' => ['string', Rule::in(Role::values())],
            'batch_id' => ['nullable', 'integer', 'exists:batches,id'],
            'users' => ['sometimes', 'array'],
            'users.*' => ['string', 'exists:users,public_id'],
            'title' => ['required', 'string', 'max:150'],
            'body' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function after(): array
    {
        return [fn ($v) => ! $this->filled('roles') && ! $this->filled('users') && $v->errors()->add('roles', 'Choose at least one role or person to send to.')];
    }

    public function messages(): array
    {
        return ['title.required' => 'Enter a title for the message.'];
    }
}
