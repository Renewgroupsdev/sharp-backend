<?php

namespace App\Enums;

enum QuestionType: string
{
    case MultipleChoice = 'multiple_choice';
    case Sorting = 'sorting';
    case Matching = 'matching';
    case Puzzle = 'puzzle';
    case ShortAnswer = 'short_answer';
    case LongAnswer = 'long_answer';
}
