<?php

namespace App\Services;

/**
 * Builds the question list one student gets for one attempt.
 *
 * Every student, and every retry, gets a different list, but the same student + quiz + attempt always gets the
 * same list back (the seed is derived from the three ids), so a rebuilt or replayed attempt is reproducible.
 *
 *  1. Each question group is shuffled on its own (Fisher-Yates driven by a seeded PRNG).
 *     Questions the student already saw in earlier attempts go to the back of their group, so a retry shows new ones first.
 *  2. Questions are dealt round-robin across the groups, in a seeded group order, until $count are chosen.
 *     That keeps every topic represented instead of letting one big group fill the list.
 *  3. The chosen questions are shuffled again to set the order shown to the student.
 */
class QuizQuestionPicker
{
    /**
     * @param  array<int, list<int>>  $pools  question ids per question-group id, each in id order
     * @param  list<int>  $seen  question ids this student got in earlier attempts of this quiz
     * @param  int  $count  how many questions to serve; 0 (or more than exist) serves them all
     * @return list<int>
     */
    public function pick(array $pools, int $studentId, int $assessmentId, int $attemptNo, int $count = 0, array $seen = []): array
    {
        $rand = $this->rng(crc32("quiz|{$assessmentId}|{$studentId}|{$attemptNo}"));
        $seen = array_flip($seen);

        $queues = [];
        foreach ($pools as $groupId => $ids) {
            $fresh = $this->shuffle(array_values(array_filter($ids, fn ($id) => ! isset($seen[$id]))), $rand);
            $old = $this->shuffle(array_values(array_filter($ids, fn ($id) => isset($seen[$id]))), $rand);
            if ($queue = array_merge($fresh, $old)) {
                $queues[$groupId] = $queue;
            }
        }

        $total = array_sum(array_map('count', $queues));
        $take = $count > 0 ? min($count, $total) : $total;

        $order = $this->shuffle(array_keys($queues), $rand);
        $chosen = [];
        while (count($chosen) < $take) {
            foreach ($order as $groupId) {
                if (count($chosen) >= $take) {
                    break;
                }
                if ($queues[$groupId]) {
                    $chosen[] = array_shift($queues[$groupId]);
                }
            }
        }

        return $this->shuffle($chosen, $rand);
    }

    /**
     * @param  list<int>  $items
     * @param  callable(): float  $rand
     * @return list<int>
     */
    private function shuffle(array $items, callable $rand): array
    {
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = (int) floor($rand() * ($i + 1));
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }

        return $items;
    }

    /** Mulberry32: a small seeded PRNG that gives the same sequence on every PHP version and platform. */
    private function rng(int $seed): callable
    {
        $state = $seed & 0xFFFFFFFF;

        return function () use (&$state): float {
            $state = ($state + 0x6D2B79F5) & 0xFFFFFFFF;
            $t = $state;
            $t = $this->imul($t ^ ($t >> 15), $t | 1);
            $t ^= ($t + $this->imul($t ^ ($t >> 7), $t | 61)) & 0xFFFFFFFF;

            return (($t ^ ($t >> 14)) & 0xFFFFFFFF) / 4294967296;
        };
    }

    /** 32-bit unsigned multiply. Split into 16-bit halves so no product passes 2^48 (a full 64-bit product would overflow into a float). */
    private function imul(int $a, int $b): int
    {
        return (($a & 0xFFFF) * $b + (((($a >> 16) & 0xFFFF) * $b & 0xFFFF) << 16)) & 0xFFFFFFFF;
    }
}
