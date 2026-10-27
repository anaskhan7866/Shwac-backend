<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ServiceJob;
use App\Models\User;
use App\Services\Operations\JobService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class JobController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = ServiceJob::query()->visibleTo($request->user())
            ->with(['customer:id,name,phone', 'address:id,line1,city,pincode,lat,lng', 'technician:id,name,phone', 'service:id,name']);

        foreach (['status', 'branch_id', 'technician_id', 'type'] as $f) {
            if ($request->filled($f)) {
                $q->whereIn($f, (array) $request->input($f));
            }
        }
        if ($request->boolean('live')) {
            $q->whereIn('status', ServiceJob::ACTIVE_STATUSES);
        }
        if ($request->boolean('unassigned')) {
            $q->whereNull('technician_id')->where('status', 'pending');
        }
        if ($request->filled('date')) {
            $q->whereDate('scheduled_date', $request->date('date'));
        }

        return response()->json($q->orderBy('scheduled_date')->latest('id')->paginate($this->perPage($request)));
    }

    public function show(Request $request, ServiceJob $job): JsonResponse
    {
        $this->ensureVisible($job, $request->user());

        return response()->json($job->load(['customer', 'address', 'service', 'booking', 'complaint', 'technician:id,name,phone', 'statusLogs.user:id,name', 'invoice']));
    }

    public function assign(Request $request, ServiceJob $job, JobService $jobs): JsonResponse
    {
        $this->ensureVisible($job, $request->user());
        $data = $request->validate(['technician_id' => ['required', 'exists:users,id']]);

        return response()->json($jobs->assign($job, User::findOrFail($data['technician_id']), $request->user()));
    }

    public function updateStatus(Request $request, ServiceJob $job, JobService $jobs): JsonResponse
    {
        $this->ensureVisible($job, $request->user());
        $data = $request->validate([
            'status' => ['required', Rule::in(ServiceJob::STATUSES)],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        return response()->json($jobs->transition($job, $data['status'], $request->user(), $data['note'] ?? null));
    }

    /** Technicians in the job's branch with current workload, for the assign picker. */
    public function technicians(Request $request): JsonResponse
    {
        $user = $request->user();
        $q = User::query()->where('user_type', User::TYPE_TECHNICIAN)->where('is_active', true)
            ->withCount(['assignedJobs as active_jobs' => fn ($q) => $q->whereIn('status', ServiceJob::ACTIVE_STATUSES)]);
        if (! $user->canSeeAllBranches()) {
            $q->where('branch_id', $user->branch_id);
        } elseif ($request->filled('branch_id')) {
            $q->where('branch_id', $request->integer('branch_id'));
        }

        return response()->json($q->orderBy('active_jobs')->get(['id', 'name', 'phone', 'branch_id']));
    }
}
