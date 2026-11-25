<?php

namespace App\Http\Requests;

use App\Enums\BatchStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBatchRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60', Rule::unique('batches', 'name')->ignore($this->route('batch'))],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'seats' => ['required', 'integer', 'min:1', 'max:5000'],
            'mentors' => ['nullable', 'integer', 'min:0', 'max:500'],
            'status' => ['sometimes', Rule::enum(BatchStatus::class)],
            'college_ids' => ['nullable', 'array'],
            'college_ids.*' => ['integer', 'distinct', 'exists:colleges,id'],
            'course_ids' => ['nullable', 'array'],
            'course_ids.*' => ['integer', 'distinct', Rule::exists('courses', 'id')->whereNull('deleted_at')],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Batch name is required.',
            'name.unique' => 'A batch with that name already exists.',
            'end_date.after' => 'End date must be after the start date.',
            'status.enum' => 'Invalid batch status.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['name' => trim((string) $this->input('name'))]);
    }
}
