<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class UserPolicy
{
    /** Create an account with the given role (null = role not yet known/validated). */
    public function create(User $actor, ?Role $role = null): Response
    {
        return match ($actor->role) {
            Role::SuperAdmin => Response::allow(),
            Role::Admin => $role === Role::SuperAdmin
                ? Response::deny('Only a Super Admin can create another Super Admin.')
                : Response::allow(),
            Role::Ob => $role === null || $role === Role::Student
                ? Response::allow()
                : Response::deny('OB accounts can only create Student accounts. Mentor, Trainer, Admin, OB, Member and Institution accounts can only be created by an Admin.'),
            default => Response::deny('You do not have permission to create accounts.'),
        };
    }

    /** Suspend, reactivate or reset the password of another account. */
    public function manage(User $actor, User $target): Response
    {
        if (! $actor->hasRole(...Role::staff())) {
            return Response::deny('You do not have permission to do that.');
        }
        if ($actor->role === Role::Ob && $target->role !== Role::Student) {
            return Response::deny('OB accounts can only manage Student accounts.');
        }
        if ($target->role === Role::SuperAdmin && $actor->role !== Role::SuperAdmin) {
            return Response::deny('Only a Super Admin can manage another Super Admin account.');
        }

        return Response::allow();
    }

    /** Read a student's attendance records. */
    public function viewAttendance(User $actor, User $student): Response
    {
        return match (true) {
            $actor->role === Role::Student && $actor->id !== $student->id => Response::deny('You can only view your own attendance.'),
            $actor->role === Role::Institution && $actor->college_id !== $student->college_id => Response::deny('You can only view attendance for your own college.'),
            default => Response::allow(),
        };
    }
}
