<?php

namespace App\Http\Requests;

use App\Models\CommunityGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCommunityGroupRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'min:2', 'max:120',
                // One custom group per name, ignoring case; deleted groups free their name.
                fn (string $attribute, mixed $value, \Closure $fail) => CommunityGroup::nameTaken((string) $value) ? $fail('A group with this name already exists.') : null,
            ],
            'kind' => ['required', Rule::in(CommunityGroup::KINDS)],
            'members' => ['required', 'array', 'min:1', 'max:1000'],
            'members.*' => ['string', 'max:60', 'distinct'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Enter a group name.',
            'name.unique' => 'A group with this name already exists.',
            'members.required' => 'Select at least one member.',
            'members.min' => 'Select at least one member.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['name' => trim((string) $this->input('name'))]);
    }
}
