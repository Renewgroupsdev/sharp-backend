<?php

namespace App\Enums;

enum BatchStatus: string
{
    case Enrolling = 'enrolling';
    case Running = 'running';
    case Closed = 'closed';
}
