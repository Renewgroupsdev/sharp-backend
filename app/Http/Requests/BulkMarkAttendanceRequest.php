<?php

namespace App\Http\Requests;

use App\Enums\AttendanceStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Mark many students the same way for one day: the same status and the same remark. */
class BulkMarkAttendanceRequest extends FormRequest
{
    public function rules(): array
    {
        $needsReason = AttendanceStatus::tryFrom((string) $this->input('status'))?->needsReason() ?? false;

        return [
            'student_ids' => ['required', 'array', 'min:1', 'max:500'],
            'student_ids.*' => ['required', 'string', 'distinct'],
            'date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'status' => ['required', Rule::enum(AttendanceStatus::class)],
            'reason' => [Rule::requiredIf($needsReason), 'nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'student_ids.required' => 'Select at least one student.',
            'student_ids.max' => 'Mark at most 500 students at a time.',
            'date.before_or_equal' => 'Attendance cannot be marked for a future date.',
            'status.enum' => 'status must be Present, Manual, or Absent.',
            'reason.required' => 'A remark is required for Manual or Absent entries.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['reason' => trim((string) $this->input('reason')) ?: null]);
    }
}
