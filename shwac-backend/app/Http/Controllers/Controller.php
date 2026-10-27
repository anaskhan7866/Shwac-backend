<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

abstract class Controller
{
    protected function perPage(Request $request): int
    {
        return max(1, min((int) $request->integer('per_page', 25), 100));
    }

    /** 404 (not 403) for records in another branch, so IDs can't be probed. */
    protected function ensureVisible(Model $model, User $user): void
    {
        if (method_exists($model, 'isVisibleTo') && ! $model->isVisibleTo($user)) {
            abort(404);
        }
    }
}
