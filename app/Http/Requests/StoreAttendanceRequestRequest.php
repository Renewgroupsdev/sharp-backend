<?php

namespace App\Http\Requests;

use App\Enums\AttendanceChoice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The student's Present / Absent report for today; an absence needs a reason. */
class StoreAttendanceRequestRequest extends FormRequest
{
    public function rules(): array
    {
        $absent = $this->input('status') === AttendanceChoice::Absent->value;

        return [
            'status' => ['required', Rule::enum(AttendanceChoice::class)],
            'reason' => [Rule::requiredIf($absent), 'nullable', 'string', 'min:3', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.enum' => 'Choose Present or Absent.',
            'reason.required' => 'Tell us why you are absent.',
            'reason.min' => 'Give a little more detail about the reason.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['reason' => trim((string) $this->input('reason')) ?: null]);
    }
}
