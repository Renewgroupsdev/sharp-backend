<?php

namespace App\Http\Requests;

use App\Support\Uploads;
use Illuminate\Foundation\Http\FormRequest;

class UploadAssessmentAttachmentRequest extends FormRequest
{
    public function rules(): array
    {
        return ['file' => ['required', ...Uploads::documentRules()]];
    }
}
