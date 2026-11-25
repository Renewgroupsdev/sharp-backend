<?php

namespace App\Http\Requests;

use App\Models\CommunityGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Rename a custom group and/or replace its members. Anything left out is unchanged. */
class UpdateCommunityGroupRequest extends FormRequest
{
    public function rules(): array
    {
        $group = $this->route('group');

        return [
            'name' => [
                'sometimes', 'required', 'string', 'min:2', 'max:120',
                fn (string $attribute, mixed $value, \Closure $fail) => CommunityGroup::nameTaken((string) $value, $group?->id) ? $fail('A group with this name already exists.') : null,
            ],
            'members' => ['sometimes', 'array', 'min:1', 'max:1000'],
            'members.*' => ['string', 'max:60', 'distinct'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'A group with this name already exists.',
            'members.min' => 'A group needs at least one member.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }
    }
}
