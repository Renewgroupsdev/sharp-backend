<?php

namespace App\Models;

use App\Support\NotificationCatalog;
use Illuminate\Database\Eloquent\Model;

class NotificationRule extends Model
{
    protected $fillable = ['event', 'enabled', 'title', 'body'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    /** What the catalog says about this rule's event (label, audience, placeholders, default wording). */
    public function meta(): array
    {
        return NotificationCatalog::get($this->event);
    }
}
