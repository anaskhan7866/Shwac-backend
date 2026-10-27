<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Restricts queries to the branch a staff member belongs to, unless they hold
 * the `branches.all` permission (Super Admin, Accountant by default).
 */
trait BranchScoped
{
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->canSeeAllBranches()) {
            return $query;
        }

        return $query->where($this->getTable().'.branch_id', $user->branch_id ?? 0);
    }

    public function isVisibleTo(User $user): bool
    {
        return $user->canSeeAllBranches() || (int) $this->branch_id === (int) $user->branch_id;
    }
}
