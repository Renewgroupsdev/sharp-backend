<?php

namespace App\Services;

use App\Enums\QuestionType;
use App\Models\Question;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Everything about a question that depends on its type: what a learner may see (never the answer), how an answer is
 * marked, and what the answer sheet shows. Stored `data` per type: multiple_choice {opts, correct[]}, sorting {items}
 * (in the right order), matching {pairs: [[left, right]]}, puzzle {puzzle, hint}, short_answer {shortAns, keywords},
 * long_answer {rubric, wordlimit} (marked by a mentor).
 */
class QuizScorer
{
    /**
     * The question as the learner sees it while answering: shuffled where the order is the puzzle, and without any answer.
     *
     * @return array<string, mixed>
     */
    public function forLearner(Question $q): array
    {
        $d = $q->data ?? [];
        $base = ['id' => $q->id, 'type' => $q->type, 'marks' => $q->marks, 'text' => $q->text, 'image' => $q->image];

        return $base + match ($q->type) {
            QuestionType::MultipleChoice => ['options' => $d['opts'] ?? [], 'multiple' => count($d['correct'] ?? []) > 1],
            QuestionType::Sorting => ['items' => $this->shuffled($d['items'] ?? [])],
            QuestionType::Matching => [
                'left' => array_map(fn ($p) => $p[0] ?? '', $d['pairs'] ?? []),
                'right' => $this->shuffled(array_map(fn ($p) => $p[1] ?? '', $d['pairs'] ?? [])),
            ],
            QuestionType::Puzzle => ['scrambled' => $this->scramble((string) ($d['puzzle'] ?? '')), 'hint' => $d['hint'] ?? null],
            QuestionType::ShortAnswer => [],
            QuestionType::LongAnswer => ['word_limit' => $d['wordlimit'] ?? null],
        };
    }

    /**
     * The question for the review screen: the same fields, in their stored (right) order so the student's answer can be shown beside it.
     *
     * @return array<string, mixed>
     */
    public function forReview(Question $q): array
    {
        $d = $q->data ?? [];
        $base = ['id' => $q->id, 'type' => $q->type, 'marks' => $q->marks, 'text' => $q->text, 'image' => $q->image];

        return $base + match ($q->type) {
            QuestionType::MultipleChoice => ['options' => $d['opts'] ?? []],
            default => [],
        };
    }

    /**
     * What the right answer is, for the answer sheet.
     *
     * @return array<string, mixed>|list<mixed>|string|null
     */
    public function answerSheet(Question $q): mixed
    {
        $d = $q->data ?? [];

        return match ($q->type) {
            QuestionType::MultipleChoice => array_values($d['correct'] ?? []),
            QuestionType::Sorting => array_values($d['items'] ?? []),
            QuestionType::Matching => array_values($d['pairs'] ?? []),
            QuestionType::Puzzle => $d['puzzle'] ?? null,
            QuestionType::ShortAnswer => ['answer' => $d['shortAns'] ?? null, 'keywords' => $d['keywords'] ?? null],
            QuestionType::LongAnswer => $d['rubric'] ?? null,
        };
    }

    /**
     * Marks one answer. `correct` is null for a question a mentor has to mark.
     *
     * @return array{marks: int, correct: ?bool}
     */
    public function grade(Question $q, mixed $answer): array
    {
        $d = $q->data ?? [];

        return match ($q->type) {
            QuestionType::MultipleChoice => $this->allOrNothing($q, $this->sameChoices(Arr::wrap($answer), $d['correct'] ?? [])),
            QuestionType::Sorting => $this->allOrNothing($q, $this->inOrder(Arr::wrap($answer), $d['items'] ?? [])),
            QuestionType::Matching => $this->matching($q, $answer),
            QuestionType::Puzzle => $this->allOrNothing($q, $this->text($answer) !== '' && $this->text($answer) === $this->text($d['puzzle'] ?? '')),
            QuestionType::ShortAnswer => $this->allOrNothing($q, $this->shortMatches($answer, $d)),
            QuestionType::LongAnswer => ['marks' => 0, 'correct' => null],
        };
    }

    private function allOrNothing(Question $q, bool $right): array
    {
        return ['marks' => $right ? $q->marks : 0, 'correct' => $right];
    }

    /** @param  list<mixed>  $given  @param  list<mixed>  $correct */
    private function sameChoices(array $given, array $correct): bool
    {
        $a = array_values(array_unique(array_map('intval', array_filter($given, 'is_numeric'))));
        $b = array_values(array_unique(array_map('intval', $correct)));
        sort($a);
        sort($b);

        return $b !== [] && $a === $b;
    }

    /** @param  list<mixed>  $given  @param  list<mixed>  $items */
    private function inOrder(array $given, array $items): bool
    {
        $a = array_map(fn ($v) => $this->text($v), array_values($given));
        $b = array_map(fn ($v) => $this->text($v), array_values($items));

        return $b !== [] && $a === $b;
    }

    /** Partial credit: marks in proportion to the pairs matched correctly. */
    private function matching(Question $q, mixed $answer): array
    {
        $pairs = $q->data['pairs'] ?? [];
        $given = [];
        foreach (Arr::wrap($answer) as $k => $v) {
            // Accepts {left: right} or [[left, right], ...].
            is_array($v) ? $given[$this->text($v[0] ?? '')] = $this->text($v[1] ?? '') : $given[$this->text($k)] = $this->text($v);
        }
        $right = count(array_filter($pairs, fn ($p) => ($given[$this->text($p[0] ?? '')] ?? null) === $this->text($p[1] ?? '')));

        return ['marks' => $pairs ? (int) floor($q->marks * $right / count($pairs)) : 0, 'correct' => $pairs !== [] && $right === count($pairs)];
    }

    /** The model answer, or any of the accepted keywords, appears in the answer. */
    private function shortMatches(mixed $answer, array $d): bool
    {
        $text = $this->text($answer);
        if ($text === '') {
            return false;
        }
        $model = $this->text($d['shortAns'] ?? '');
        $keywords = array_filter(array_map(fn ($k) => $this->text($k), explode(',', (string) ($d['keywords'] ?? ''))));

        return ($model !== '' && $text === $model)
            || collect($keywords)->contains(fn ($k) => str_contains($text, $k));
    }

    /** Lower case, single spaces: "  Hello   World " and "hello world" are the same answer. */
    public function text(mixed $value): string
    {
        return is_scalar($value) ? Str::lower(trim((string) preg_replace('/\s+/u', ' ', (string) $value))) : '';
    }

    /** @param  list<mixed>  $items  @return list<mixed> never in the right order when there is a choice */
    private function shuffled(array $items): array
    {
        $items = array_values($items);
        for ($i = 0; $i < 5 && count($items) > 1; $i++) {
            $mixed = $items;
            shuffle($mixed);
            if ($mixed !== $items) {
                return $mixed;
            }
        }

        return $items;
    }

    /** Letters of each word shuffled; a one-letter word stays as it is. */
    private function scramble(string $phrase): string
    {
        return collect(preg_split('/\s+/u', trim($phrase)) ?: [])->map(function (string $word) {
            $letters = mb_str_split($word);
            for ($i = 0; $i < 5 && count($letters) > 2; $i++) {
                $mixed = $letters;
                shuffle($mixed);
                if ($mixed !== $letters) {
                    return implode('', $mixed);
                }
            }

            return $word;
        })->implode(' ');
    }
}
