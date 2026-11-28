<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Api\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Uploads the file of a document / video / image lesson; the lesson then keeps the returned link. Route is staff-only. */
class LessonFileController extends ApiController
{
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:51200', 'mimes:pdf,doc,docx,txt,ppt,pptx,mp4,webm,mov,png,jpg,jpeg,gif,webp'],
        ]);
        $file = $request->file('file');
        $path = $file->storeAs('lessons', bin2hex(random_bytes(20)).'.'.strtolower($file->getClientOriginalExtension() ?: $file->extension()), 'public');

        return $this->ok(['url' => Storage::disk('public')->url($path), 'name' => $file->getClientOriginalName(), 'size' => $file->getSize()]);
    }
}
