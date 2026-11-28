<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Api\ApiController;
use App\Models\CertificateSetting;
use App\Models\CertificateTemplate;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Certificate templates: named designs that a new certificate can start from. Staff only, by route. */
class CertificateTemplateController extends ApiController
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        return $this->ok(['templates' => CertificateTemplate::query()->orderBy('name')->get()->map(fn (CertificateTemplate $t) => $this->payload($t))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $template = CertificateTemplate::create($this->validated($request) + ['created_by' => $request->user()->id]);
        $this->audit->record($request->user(), 'Created certificate template', null, $template->name);

        return $this->ok(['template' => $this->payload($template)], 201);
    }

    public function update(Request $request, CertificateTemplate $template): JsonResponse
    {
        $template->update($this->validated($request, $template));
        $this->audit->record($request->user(), 'Updated certificate template', null, $template->name);

        return $this->ok(['template' => $this->payload($template->refresh())]);
    }

    public function destroy(Request $request, CertificateTemplate $template): JsonResponse
    {
        $template->delete(); // certificates made from it keep their own copy of the design
        $this->audit->record($request->user(), 'Deleted certificate template', null, $template->name);

        return $this->ok(['message' => 'Template deleted.']);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?CertificateTemplate $template = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('certificate_templates', 'name')->ignore($template?->id)],
            'description' => ['nullable', 'string', 'max:200'],
            'design' => ['required', 'array'],
            'design.template' => ['required', Rule::in(CertificateSetting::TEMPLATES)],
            'design.type' => ['required', Rule::in(CertificateSetting::TYPES)],
            'design.title' => ['required', 'string', 'max:120'],
            'design.body' => ['required', 'string', 'max:1000'],
            'design.accent' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'design.show_profile' => ['required', 'boolean'],
            'design.show_qr' => ['required', 'boolean'],
            'design.show_logo' => ['sometimes', 'boolean'],
            'design.logo_image' => ['sometimes', 'nullable', 'string', 'max:200000', 'regex:/^data:image\/(png|jpeg);base64,[A-Za-z0-9+\/=]+$/'],
            'design.logos' => ['sometimes', 'array', 'max:4'],
            'design.logos.*.image' => ['required', 'string', 'max:200000', 'regex:/^data:image\/(png|jpeg);base64,[A-Za-z0-9+\/=]+$/'],
            'design.logos.*.position' => ['required', Rule::in(CertificateSetting::LOGO_POSITIONS)],
            'design.logo_position' => ['sometimes', Rule::in(CertificateSetting::LOGO_POSITIONS)],
            'design.signature_name' => ['nullable', 'string', 'max:80'],
            'design.signature_role' => ['nullable', 'string', 'max:80'],
            // PNG / JPEG only (an SVG could carry script); about 150 KB once encoded.
            'design.signature_image' => ['nullable', 'string', 'max:200000', 'regex:/^data:image\/(png|jpeg);base64,[A-Za-z0-9+\/=]+$/'],
        ]);
        $data['design'] = collect($data['design'])->only(['template', 'type', 'title', 'body', 'accent', 'show_profile', 'show_qr', 'show_logo', 'logo_position', 'logo_image', 'logos', 'signature_name', 'signature_role', 'signature_image'])->all();

        return $data;
    }

    /** @return array<string, mixed> */
    private function payload(CertificateTemplate $t): array
    {
        return ['id' => $t->id, 'name' => $t->name, 'description' => $t->description, 'design' => $t->design, 'updated_at' => $t->updated_at];
    }
}
