<?php

namespace App\Services;

use App\Models\CourseCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class CourseCategoryService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @return Collection<int, CourseCategory> */
    public function list(?string $search = null): Collection
    {
        // Trashed courses still hold their category (restrictOnDelete), so they count as "in use".
        return CourseCategory::query()
            ->search($search)
            ->withCount(['courses' => fn ($q) => $q->withTrashed()])
            ->orderBy('name')
            ->get();
    }

    public function create(User $actor, array $data): CourseCategory
    {
        $category = CourseCategory::create($data)->refresh(); // pick up the status default
        $this->audit->record($actor, 'Added course category', (string) $category->id, $category->name);

        return $category->loadCount(['courses' => fn ($q) => $q->withTrashed()]);
    }

    public function update(User $actor, CourseCategory $category, array $data): CourseCategory
    {
        $category->update($data);
        $this->audit->record($actor, 'Updated course category', (string) $category->id, $category->name);

        return $category->loadCount(['courses' => fn ($q) => $q->withTrashed()]);
    }

    public function delete(User $actor, CourseCategory $category): void
    {
        $used = $category->courses()->withTrashed()->count();
        if ($used > 0) {
            throw ValidationException::withMessages(['category' => "{$used} course(s) use “{$category->name}” — change their category first."]);
        }

        $name = $category->name;
        $id = (string) $category->id;
        $category->delete();
        $this->audit->record($actor, 'Deleted course category', $id, $name);
    }
}
