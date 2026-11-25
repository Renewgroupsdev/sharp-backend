<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PublicIdGenerator
{
    /**
     * Next public ID for the role. Must run inside a transaction: an advisory
     * lock serialises concurrent creations so two requests never get one number.
     */
    public function next(Role $role, ?string $collegeCode = null): string
    {
        if ($role === Role::Institution) {
            return $this->institutionId($collegeCode);
        }

        [$prefix, $width] = $role === Role::Student
            ? ['SH'.config('sharp.year').'/'.$collegeCode.'/', 4]
            : [$role->idPrefix().'-', 3];

        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['public_id:'.$prefix]);

        $max = User::query()
            ->where('role', $role->value)
            ->where('public_id', 'like', $prefix.'%')
            ->selectRaw("MAX(substring(public_id from '([0-9]+)$')::int) AS n")
            ->value('n');

        return $prefix.str_pad((string) ((int) $max + 1), $width, '0', STR_PAD_LEFT);
    }

    private function institutionId(string $collegeCode): string
    {
        $base = 'IN-'.$collegeCode;
        $taken = User::query()->where('public_id', 'like', $base.'%')->pluck('public_id')->flip();

        $id = $base;
        for ($n = 2; isset($taken[$id]); $n++) {
            $id = $base.'-'.$n;
        }

        return $id;
    }
}
