<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ChapterRules;
use Illuminate\Foundation\Http\FormRequest;

/** Replace a course's curriculum (chapters and their lessons). */
class SyncCourseChaptersRequest extends FormRequest
{
    use ChapterRules;

    public function rules(): array
    {
        return ['chapters' => ['present', 'array', 'max:100']] + $this->chapterRules();
    }
}
