<?php

namespace App\Enums;

enum CertificateStatus: string
{
    case Pending = 'Pending';
    case Issued = 'Issued';
    case Rejected = 'Rejected';
    case Cancelled = 'Cancelled';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
