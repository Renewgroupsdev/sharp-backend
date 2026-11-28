<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A named certificate design: layout, colour, wording and signature. A certificate made from it keeps a copy. */
class CertificateTemplate extends Model
{
    protected $fillable = ['name', 'description', 'design', 'created_by'];

    protected function casts(): array
    {
        return ['design' => 'array'];
    }
}
