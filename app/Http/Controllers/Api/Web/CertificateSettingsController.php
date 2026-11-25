<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\UpdateCertificateSettingsRequest;
use App\Models\CertificateSetting;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;

/** Programme-wide certificate settings: template, numbering, minimum attendance and score. Writes are staff-only by route. */
class CertificateSettingsController extends ApiController
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function show(): JsonResponse
    {
        return $this->ok(['settings' => $this->payload(CertificateSetting::current())]);
    }

    public function update(UpdateCertificateSettingsRequest $request): JsonResponse
    {
        $data = $request->validated();
        $settings = CertificateSetting::current();

        if (($data['remove_signature_image'] ?? false) === true) {
            $data['signature_image'] = null;
        } elseif (empty($data['signature_image'])) {
            unset($data['signature_image']); // not sent: keep the current signature
        }
        unset($data['remove_signature_image']);

        $settings->update($data + ['updated_by' => $request->user()->id]);
        $this->audit->record($request->user(), 'Updated certificate settings', null, "{$settings->template} · min attendance {$settings->min_attendance}% · min score {$settings->min_score}%");

        return $this->ok(['settings' => $this->payload($settings->refresh())]);
    }

    /** @return array<string, mixed> */
    private function payload(CertificateSetting $s): array
    {
        return $s->design() + ['prefix' => $s->prefix, 'min_attendance' => $s->min_attendance, 'min_score' => $s->min_score];
    }
}
