<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateNotificationRuleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'enabled' => ['sometimes', 'boolean'],
            'title' => ['sometimes', 'required', 'string', 'max:150'],
            'body' => ['sometimes', 'required', 'string', 'max:500'],
        ];
    }
}
