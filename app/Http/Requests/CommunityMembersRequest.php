<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** The people to add to a group, by public id. */
class CommunityMembersRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'members' => ['required', 'array', 'min:1', 'max:1000'],
            'members.*' => ['string', 'max:60', 'distinct'],
        ];
    }

    public function messages(): array
    {
        return ['members.required' => 'Select at least one person to add.', 'members.min' => 'Select at least one person to add.'];
    }
}
