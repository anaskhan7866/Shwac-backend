<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Staff & technician accounts and role management.
 * Branch Managers manage users of their own branch and cannot grant super_admin or all-branch roles.
 */
class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        $q = User::query()->whereIn('user_type', [User::TYPE_STAFF, User::TYPE_TECHNICIAN])
            ->with('roles:id,slug,name', 'branch:id,name');
        if (! $actor->canSeeAllBranches()) {
            $q->where('branch_id', $actor->branch_id);
        }
        if ($request->filled('user_type')) { $q->where('user_type', $request->input('user_type')); }
        if ($request->filled('role')) { $q->whereHas('roles', fn ($r) => $r->where('slug', $request->input('role'))); }
        if ($s = $request->string('q')->trim()->value()) {
            $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('phone', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%"));
        }

        return response()->json($q->orderBy('name')->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:120', 'unique:users,email', 'required_without:phone'],
            'phone' => ['nullable', 'regex:/^[6-9]\d{9}$/', 'unique:users,phone', 'required_without:email'],
            'password' => ['required', Password::min(8)->letters()->numbers()],
            'user_type' => ['required', Rule::in([User::TYPE_STAFF, User::TYPE_TECHNICIAN])],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'roles' => ['array'],
            'roles.*' => ['string', 'exists:roles,slug'],
        ]);
        $this->guardRoles($actor, $data['roles'] ?? []);
        $branchId = $actor->canSeeAllBranches() ? ($data['branch_id'] ?? null) : $actor->branch_id;

        $user = User::create(collect($data)->except('roles')->all() + ['branch_id' => $branchId]);
        $roles = $data['roles'] ?? [];
        if ($user->isTechnician()) {
            $roles[] = 'technician';
        }
        $user->assignRole(...array_unique($roles));

        return response()->json($user->load('roles:id,slug,name'), 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();
        abort_if(! $user->isStaff(), 404);
        abort_if(! $actor->canSeeAllBranches() && $user->branch_id !== $actor->branch_id, 404);
        abort_if($user->isSuperAdmin() && ! $actor->isSuperAdmin(), 403, 'Only a Super Admin can edit a Super Admin.');

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'email' => ['nullable', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'regex:/^[6-9]\d{9}$/', Rule::unique('users', 'phone')->ignore($user->id)],
            'password' => ['sometimes', Password::min(8)->letters()->numbers()],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'is_active' => ['sometimes', 'boolean'],
            'roles' => ['sometimes', 'array'],
            'roles.*' => ['string', 'exists:roles,slug'],
        ]);
        if (! $actor->canSeeAllBranches()) {
            unset($data['branch_id']);
        }
        if (array_key_exists('roles', $data)) {
            $this->guardRoles($actor, $data['roles']);
            $roles = $data['roles'];
            if ($user->isTechnician()) {
                $roles[] = 'technician';
            }
            $user->roles()->sync(Role::whereIn('slug', array_unique($roles))->pluck('id'));
        }
        $user->update(collect($data)->except('roles')->all());
        if (($data['is_active'] ?? true) === false) {
            $user->tokens()->delete();
        }

        return response()->json($user->load('roles:id,slug,name'));
    }

    public function roles(): JsonResponse
    {
        return response()->json([
            'roles' => Role::with('permissions:id,slug')->get(),
            'permissions' => Permission::orderBy('group')->get(['id', 'slug', 'name', 'group']),
        ]);
    }

    /** Super Admin only (route-level). Replace a role's permission set. */
    public function updateRole(Request $request, Role $role): JsonResponse
    {
        abort_if($role->slug === 'super_admin', 422, 'Super Admin always has every permission.');
        $data = $request->validate(['permissions' => ['present', 'array'], 'permissions.*' => ['string', 'exists:permissions,slug']]);
        $role->permissions()->sync(Permission::whereIn('slug', $data['permissions'])->pluck('id'));

        return response()->json($role->load('permissions:id,slug'));
    }

    private function guardRoles(User $actor, array $roles): void
    {
        if ($actor->isSuperAdmin()) {
            return;
        }
        $forbidden = Role::whereIn('slug', $roles)
            ->where(fn ($q) => $q->where('slug', 'super_admin')->orWhereHas('permissions', fn ($p) => $p->whereIn('slug', ['branches.all', 'settings.manage', 'users.manage'])))
            ->pluck('slug');
        abort_if($forbidden->isNotEmpty(), 403, 'You cannot grant: '.$forbidden->implode(', '));
    }
}
