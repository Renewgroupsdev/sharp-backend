<?php

namespace App\Enums;

enum AssessmentType: string
{
    case Quiz = 'quiz';
    case Assignment = 'assignment';
    case Assessment = 'assessment';
}
