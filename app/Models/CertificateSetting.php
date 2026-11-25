<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The single row of programme-wide certificate settings: what a certificate looks like and who is eligible. */
class CertificateSetting extends Model
{
    public const TEMPLATES = ['Classic', 'Modern', 'Minimal'];

    public const TYPES = ['Completion', 'Participation', 'Achievement', 'Merit'];

    public const DEFAULT_BODY = 'This is to certify that {student} has successfully completed the course “{course}” with a score of {score}% on {date}.';

    protected $fillable = [
        'template', 'type', 'title', 'body', 'prefix', 'min_attendance', 'min_score', 'show_profile', 'show_qr',
        'signature_name', 'signature_role', 'signature_image', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['min_attendance' => 'integer', 'min_score' => 'integer', 'show_profile' => 'boolean', 'show_qr' => 'boolean'];
    }

    public static function current(): self
    {
        return static::query()->orderBy('id')->first()
            ?? static::create(['body' => self::DEFAULT_BODY, 'signature_name' => 'K. Ramesh', 'signature_role' => 'Programme Administrator'])->refresh(); // refresh: pick up the column defaults
    }

    /** The artwork fields. An issued certificate keeps a copy of these, so later edits never change it. */
    public function design(): array
    {
        return [
            'template' => $this->template, 'type' => $this->type, 'title' => $this->title, 'body' => $this->body,
            'show_profile' => $this->show_profile, 'show_qr' => $this->show_qr,
            'signature_name' => $this->signature_name, 'signature_role' => $this->signature_role, 'signature_image' => $this->signature_image,
        ];
    }
}
