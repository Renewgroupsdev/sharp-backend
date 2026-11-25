<?php

namespace App\Services;

use App\Models\College;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Enums\CollegeStatus;


class CollegeService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @return Collection<int, College> */
    public function list(): Collection
    {
        return College::query()->withCount('students')->orderBy('name')->get();
    }

    public function create(User $actor, array $data): College
    {
        $code = Str::upper($data['code'] ?? '') ?: $this->suggestCode($data['name']);

        $college = College::create([
            'name' => $data['name'],
            'code' => $code,
            'city' => $data['city'] ?? null,
            'principal_name' => $data['principal_name'] ?? null,
            'email' => $data['email'] ?? null,
            'mobile' => $data['mobile'] ?? null,
            'lat' => $data['lat'] ?? null,
            'lng' => $data['lng'] ?? null,
            'geofence_radius_m' => $data['geofence_radius_m'] ?? 200,
        ])->refresh();

        $this->audit->record($actor, 'Added college', $code, $college->name);

        return $college;
    }

    /** Suggests a unique 3-letter code from the college name. */
    public function suggestCode(string $name): string
    {
        $words = array_values(array_filter(preg_split('/\s+/', preg_replace('/[^A-Za-z\s]/', '', $name))));

        $base = match (true) {
            count($words) >= 3 => $words[0][0].$words[1][0].$words[2][0],
            count($words) === 2 => $words[0][0].$words[1][0].substr($words[1], 1, 1),
            count($words) === 1 => substr($words[0], 0, 3),
            default => 'CLG',
        };
        $base = Str::upper(str_pad(substr($base, 0, 3), 3, 'X'));

        // One query for every candidate sharing the first two letters.
        $taken = College::query()
            ->where('code', 'like', substr($base, 0, 2).'%')
            ->pluck('code')
            ->flip();

        $code = $base;
        for ($n = 2; isset($taken[$code]); $n++) {
            $code = substr($base, 0, 2).($n <= 26 ? chr(64 + $n) : $n - 26);
        }

        return $code;
    }

    public function update(User $actor, College $college, array $data): College
    {
        $college->update([
            'name' => $data['name'],
            'code' => $data['code'] ?? $college->code,
            'city' => $data['city'] ?? $college->city,
            'email' => $data['email'] ?? $college->email,
            'mobile' => $data['mobile'] ?? $college->mobile,
            'principal_name' => $data['principal_name'] ?? $college->principal_name,
            // The geofence can be cleared (send both empty), so these follow the request instead of keeping the old value.
            'lat' => array_key_exists('lat', $data) ? $data['lat'] : $college->lat,
            'lng' => array_key_exists('lng', $data) ? $data['lng'] : $college->lng,
            'geofence_radius_m' => $data['geofence_radius_m'] ?? $college->geofence_radius_m,
            'status' => CollegeStatus::tryFrom(
                ucfirst(strtolower(trim((string) ($data['status'] ?? ''))))
            )?->value ?? $college->status,
        ]);
        $college->refresh();

        $this->audit->record(
            $actor,
            'Updated college',
            $college->code,
            json_encode($college->only(['name', 'code', 'city', 'email', 'mobile', 'principal_name', 'status']))
        );

        return $college;
    }

    public function delete(User $actor, College $college): void
    {
        $code = $college->code;
        $name = $college->name;

        $studentCount = $college->students()->count();
        if ($studentCount > 0) {
            throw ValidationException::withMessages(['institute' => 'You cannot delete a college that has students.']);
        }

        $college->delete();

        $this->audit->record($actor, 'Deleted college', $code, $name);
    }
}
