<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\College */
class CollegeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'city' => $this->city,
            'principal_name' => $this->principal_name,
            'mobile' => $this->mobile,
            'email' => $this->email,
            'status' => $this->status,
            'lat' => $this->lat === null ? null : (float) $this->lat,
            'lng' => $this->lng === null ? null : (float) $this->lng,
            'geofence_radius_m' => (int) $this->geofence_radius_m,
            'student_count' => $this->whenCounted('students'),
            'created_at' => $this->created_at,
        ];
    }
}
