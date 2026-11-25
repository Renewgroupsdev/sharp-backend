<?php

namespace Tests\Unit;

use App\Services\QuizQuestionPicker;
use PHPUnit\Framework\TestCase;

class QuizQuestionPickerTest extends TestCase
{
    private function pools(): array
    {
        return [1 => range(1, 10), 2 => range(11, 14), 3 => range(15, 30)];
    }

    public function test_the_same_student_quiz_and_attempt_always_get_the_same_list(): void
    {
        $p = new QuizQuestionPicker();
        $this->assertSame($p->pick($this->pools(), 5, 9, 1, 8), $p->pick($this->pools(), 5, 9, 1, 8));
    }

    public function test_students_and_attempts_get_different_lists(): void
    {
        $p = new QuizQuestionPicker();
        $a = $p->pick($this->pools(), 5, 9, 1, 8);
        $this->assertNotSame($a, $p->pick($this->pools(), 6, 9, 1, 8));
        $this->assertNotSame($a, $p->pick($this->pools(), 5, 9, 2, 8));
    }

    public function test_count_limits_the_list_without_repeats_and_zero_serves_everything(): void
    {
        $p = new QuizQuestionPicker();
        $some = $p->pick($this->pools(), 5, 9, 1, 8);
        $this->assertCount(8, $some);
        $this->assertCount(8, array_unique($some));

        $all = $p->pick($this->pools(), 5, 9, 1, 0);
        $this->assertCount(30, $all);
        $this->assertEqualsCanonicalizing(range(1, 30), $all);
        $this->assertCount(30, $p->pick($this->pools(), 5, 9, 1, 99)); // asking for more than exist serves them all
    }

    public function test_every_group_is_represented_when_the_count_allows(): void
    {
        $p = new QuizQuestionPicker();
        $list = $p->pick($this->pools(), 5, 9, 1, 3);
        $groups = array_map(fn ($id) => $id <= 10 ? 1 : ($id <= 14 ? 2 : 3), $list);
        $this->assertEqualsCanonicalizing([1, 2, 3], $groups);
    }

    public function test_a_retry_prefers_questions_not_seen_before(): void
    {
        $p = new QuizQuestionPicker();
        $first = $p->pick($this->pools(), 5, 9, 1, 10);
        $retry = $p->pick($this->pools(), 5, 9, 2, 10, $first);
        $this->assertLessThan(10, count(array_intersect($first, $retry)));
        $this->assertCount(10, array_unique($retry));
    }
}
