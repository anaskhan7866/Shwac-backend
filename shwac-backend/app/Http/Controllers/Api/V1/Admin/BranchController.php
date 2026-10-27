<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BranchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $q = Branch::query()->orderBy('name');
        if (! $user->canSeeAllBranches()) {
            $q->whereKey($user->branch_id);
        }

        return response()->json($q->get());
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(Branch::create($request->validate($this->rules())), 201);
    }

    public function update(Request $request, Branch $branch): JsonResponse
    {
        $branch->update($request->validate($this->rules($branch->id, true)));

        return response()->json($branch);
    }

    private function rules(?int $id = null, bool $partial = false): array
    {
        $r = $partial ? 'sometimes' : 'required';

        return [
            'name' => [$r, 'string', 'max:120'],
            'code' => [$r, 'alpha_num', 'max:10', Rule::unique('branches', 'code')->ignore($id)],
            'gstin' => ['nullable', 'regex:/^\d{2}[A-Z]{5}\d{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/'],
            'state' => [$r, 'string', 'max:60'],
            'state_code' => [$r, 'digits:2'],
            'invoice_prefix' => ['sometimes', 'alpha_num', 'max:6'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:80'],
            'pincode' => ['nullable', 'digits:6'],
            'phone' => ['nullable', 'string', 'max:15'],
            'email' => ['nullable', 'email'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
