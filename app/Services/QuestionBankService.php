<?php

namespace App\Services;

use App\Models\Question;
use App\Models\QuestionGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/** Question groups and the questions in them. Any change here re-totals the quizzes that draw from the bank. */
class QuestionBankService
{
    public function __construct(private readonly AuditLogger $audit, private readonly AssessmentService $assessments) {}

    /** @return Collection<int, QuestionGroup> */
    public function groups(): Collection
    {
        return QuestionGroup::query()->withCount(['questions', 'quizzes'])->orderBy('type')->orderBy('title')->get();
    }

    public function saveGroup(User $actor, array $data, ?QuestionGroup $group = null): QuestionGroup
    {
        $group ??= new QuestionGroup(['type' => $data['type'] ?? 'quiz']);
        $group->fill(['title' => $data['title']])->save();

        $this->audit->record($actor, $group->wasRecentlyCreated ? 'Added question group' : 'Renamed question group', (string) $group->id, $group->title);

        return $group->loadCount(['questions', 'quizzes']);
    }

    public function deleteGroup(User $actor, QuestionGroup $group): void
    {
        $count = $group->questions()->count();
        if ($count > 0) {
            throw ValidationException::withMessages(['group' => "{$count} question(s) are in {$group->title} — delete or move them first."]);
        }

        $title = $group->title;
        $id = (string) $group->id;
        $group->delete();
        $this->audit->record($actor, 'Deleted question group', $id, $title);
    }

    /** @return Collection<int, Question> */
    public function questions(array $filters = []): Collection
    {
        return Question::query()
            ->with('group:id,title')
            ->when($filters['group_id'] ?? null, fn ($q, $id) => $q->where('question_group_id', $id))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where('text', 'like', "%{$term}%"))
            ->orderBy('id')
            ->get();
    }

    public function saveQuestion(User $actor, array $data, ?Question $question = null): Question
    {
        $question ??= new Question;
        $question->fill([
            'question_group_id' => $data['group_id'],
            'type' => $data['type'],
            'marks' => $data['marks'],
            'text' => $data['text'],
            'data' => $data['data'] ?? null,
            'explanation' => $data['explanation'] ?? null,
            // Editing without sending an image keeps the stored one.
            'image' => $data['image'] ?? $question->image,
        ])->save();
        $this->assessments->refreshAllQuizTotals();

        $this->audit->record($actor, $question->wasRecentlyCreated ? 'Added question' : 'Updated question', (string) $question->id, "{$data['type']} · {$data['marks']} mark(s)");

        return $question->load('group:id,title');
    }

    public function deleteQuestion(User $actor, Question $question): void
    {
        $id = (string) $question->id;
        $question->delete();
        $this->assessments->refreshAllQuizTotals();

        $this->audit->record($actor, 'Deleted question', $id);
    }
}
