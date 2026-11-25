<?php

namespace Tests\Unit;

use App\Models\QuizAttempt;
use Tests\TestCase;

class QuizAttemptOutcomeTest extends TestCase
{
    private function make(array $attrs): QuizAttempt
    {
        return new QuizAttempt($attrs);
    }

    public function test_outcome_follows_the_attempt_state(): void
    {
        $t = now();
        $this->assertSame('in_progress', $this->make(['submitted_at' => null])->outcome());
        $this->assertSame('timed_out', $this->make(['submitted_at' => $t, 'expires_at' => $t])->outcome());
        $this->assertSame('needs_review', $this->make(['submitted_at' => $t, 'needs_manual_grading' => true, 'passed' => true])->outcome());
        $this->assertSame('passed', $this->make(['submitted_at' => $t, 'passed' => true])->outcome());
        $this->assertSame('not_passed', $this->make(['submitted_at' => $t, 'passed' => false])->outcome());
        $this->assertSame('not_passed', $this->make(['submitted_at' => $t, 'expires_at' => $t->copy()->addMinute(), 'passed' => false])->outcome());
    }

    public function test_an_attempt_the_timer_submitted_at_the_deadline_is_not_timed_out_once_it_was_marked(): void
    {
        $t = now();
        // Submitted in the same second as the deadline, but marked (total > 0): a real attempt waiting for a mentor.
        $this->assertSame('needs_review', $this->make(['submitted_at' => $t, 'expires_at' => $t, 'total' => 17, 'needs_manual_grading' => true])->outcome());
        $this->assertSame('not_passed', $this->make(['submitted_at' => $t, 'expires_at' => $t, 'total' => 17, 'passed' => false])->outcome());
    }
}
