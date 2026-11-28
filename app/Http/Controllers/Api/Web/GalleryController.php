<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Api\ApiController;
use App\Models\GalleryItem;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * The Gallery: event photos and videos. Everyone who is signed in can look; staff add, edit and remove.
 * Files are private: each item comes with a signed link (valid a few hours) that <img> and <video> can open directly.
 */
class GalleryController extends ApiController
{
    private const IMAGES = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    private const VIDEOS = ['mp4', 'webm', 'mov'];

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $items = GalleryItem::query()->with('uploader:id,name')
            ->when($request->query('type') && in_array($request->query('type'), ['image', 'video'], true), fn ($q) => $q->where('type', $request->query('type')))
            ->orderByDesc('event_date')->orderByDesc('id')->limit(500)->get();

        return $this->ok(['items' => $items->map(fn (GalleryItem $i) => $this->shape($i))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'event' => ['nullable', 'string', 'max:150'],
            'event_date' => ['nullable', 'date_format:Y-m-d'],
            // Photos up to 8 MB, videos up to 100 MB (the server's upload limit applies as well).
            'files' => ['required', 'array', 'min:1', 'max:20'],
            'files.*' => ['file', 'mimes:'.implode(',', [...self::IMAGES, ...self::VIDEOS]), 'max:102400'],
        ], ['files.required' => 'Choose at least one photo or video.', 'files.*.mimes' => 'Upload JPG, PNG, GIF, WebP, MP4, WebM or MOV files.', 'files.*.max' => 'A file is too large (100 MB at most).']);

        $created = [];
        foreach ($request->file('files') as $n => $file) {
            $ext = strtolower($file->getClientOriginalExtension());
            $video = in_array($ext, self::VIDEOS, true);
            if (! $video && $file->getSize() > 8 * 1024 * 1024) {
                abort(422, 'A photo must be 8 MB or smaller.');
            }
            $count = count($request->file('files'));
            $created[] = GalleryItem::create([
                'title' => $count > 1 ? $data['title'].' ('.($n + 1).')' : $data['title'],
                'description' => $data['description'] ?? null, 'event' => $data['event'] ?? null, 'event_date' => $data['event_date'] ?? null,
                'type' => $video ? 'video' : 'image',
                'file_path' => $file->store('gallery', 'local'),
                'file_name' => Str::limit($file->getClientOriginalName(), 200, ''),
                'mime' => $file->getMimeType() ?: ($video ? 'video/mp4' : 'image/jpeg'),
                'size' => $file->getSize(),
                'uploaded_by' => $request->user()->id,
            ]);
        }
        $this->audit->record($request->user(), 'Added to the Gallery', $data['title'], count($created).' file(s)');

        return $this->ok(['items' => collect($created)->map(fn (GalleryItem $i) => $this->shape($i->load('uploader:id,name')))->values()], 201);
    }

    public function update(Request $request, GalleryItem $item): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'event' => ['nullable', 'string', 'max:150'],
            'event_date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $item->update($data);
        $this->audit->record($request->user(), 'Edited a Gallery item', $item->title);

        return $this->ok(['item' => $this->shape($item->load('uploader:id,name'))]);
    }

    public function destroy(Request $request, GalleryItem $item): JsonResponse
    {
        Storage::disk('local')->delete($item->file_path);
        $item->delete();
        $this->audit->record($request->user(), 'Removed a Gallery item', $item->title);

        return $this->ok(['message' => 'Removed.']);
    }

    /** Opened through the signed link; supports ranges so videos can seek. */
    public function file(GalleryItem $item)
    {
        abort_unless(Storage::disk('local')->exists($item->file_path), 404, 'File not found.');

        return response()->file(Storage::disk('local')->path($item->file_path), [
            'Content-Type' => $item->mime, 'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline; filename="'.addslashes($item->file_name).'"',
        ]);
    }

    /** @return array<string, mixed> */
    private function shape(GalleryItem $i): array
    {
        return [
            'id' => $i->id, 'title' => $i->title, 'description' => $i->description, 'event' => $i->event,
            'event_date' => $i->event_date?->toDateString(), 'type' => $i->type, 'file_name' => $i->file_name, 'mime' => $i->mime, 'size' => $i->size,
            'url' => URL::temporarySignedRoute('gallery.file', now()->addHours(6), ['item' => $i->id]),
            'uploaded_by' => $i->uploader?->name, 'created_at' => $i->created_at,
        ];
    }
}
