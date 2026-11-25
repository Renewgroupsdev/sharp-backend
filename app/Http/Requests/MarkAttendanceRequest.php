<?php

namespace App\Http\Requests;

use App\Enums\AttendanceStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MarkAttendanceRequest extends FormRequest
{
    public function rules(): array
    {
        $needsReason = AttendanceStatus::tryFrom((string) $this->input('status'))?->needsReason() ?? false;

        return [
            'student_id' => ['required', 'string'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['required', Rule::enum(AttendanceStatus::class)],
            'reason' => [Rule::requiredIf($needsReason), 'nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.enum' => 'status must be Present, Manual, or Absent.',
            'reason.required' => 'A reason is required for Manual or Absent entries.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'student_id' => trim((string) $this->input('student_id')),
            'reason' => trim((string) $this->input('reason')) ?: null,
        ]);
    }
}
