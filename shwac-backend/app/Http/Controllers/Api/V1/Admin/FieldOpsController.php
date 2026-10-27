<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Expense;
use App\Models\LocationPing;
use App\Models\ServiceJob;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Admin: staff attendance, live technician map, location trail and expense approvals. */
class FieldOpsController extends Controller
{
    private function technicians(Request $request)
    {
        $u = $request->user();
        $q = User::query()->where('user_type', User::TYPE_TECHNICIAN)->where('is_active', true);
        if (! $u->canSeeAllBranches()) {
            $q->where('branch_id', $u->branch_id);
        } elseif ($request->filled('branch_id')) {
            $q->where('branch_id', $request->integer('branch_id'));
        }

        return $q;
    }

    /** Live map: last known position, duty status and current job for every technician. */
    public function live(Request $request): JsonResponse
    {
        $techs = $this->technicians($request)->orderBy('name')->get();
        $att = Attendance::whereIn('user_id', $techs->pluck('id'))->whereDate('date', today())->get()->keyBy('user_id');
        $jobs = ServiceJob::whereIn('technician_id', $techs->pluck('id'))->whereIn('status', ServiceJob::ACTIVE_STATUSES)
            ->with('customer:id,name', 'address:id,line1,city,lat,lng')->get()->groupBy('technician_id');

        return response()->json($techs->map(function (User $t) use ($att, $jobs) {
            $a = $att->get($t->id);
            $mine = $jobs->get($t->id, collect());
            $current = $mine->firstWhere('status', 'in_progress') ?? $mine->firstWhere('status', 'en_route');

            return [
                'id' => $t->id, 'name' => $t->name, 'phone' => $t->phone, 'branch_id' => $t->branch_id,
                'duty' => ! $a ? 'not_checked_in' : ($a->check_out_at ? 'checked_out' : 'on_duty'),
                'checked_in_at' => $a?->check_in_at, 'checked_out_at' => $a?->check_out_at,
                'lat' => $t->last_lat, 'lng' => $t->last_lng, 'last_seen_at' => $t->last_seen_at,
                'stale' => ! $t->last_seen_at || $t->last_seen_at->lt(now()->subMinutes(15)),
                'active_jobs' => $mine->count(),
                'current_job' => $current ? ['id' => $current->id, 'number' => $current->number, 'status' => $current->status, 'customer' => $current->customer?->name, 'address' => $current->address] : null,
            ];
        }));
    }

    /** GPS trail for one technician on one day (for the route replay on the map). */
    public function trail(Request $request, User $user): JsonResponse
    {
        abort_unless($this->technicians($request)->whereKey($user->id)->exists(), 404);
        $day = $request->date('date') ?? today();

        return response()->json([
            'attendance' => Attendance::where('user_id', $user->id)->whereDate('date', $day)->first(),
            'points' => LocationPing::where('user_id', $user->id)
                ->whereBetween('recorded_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
                ->orderBy('recorded_at')->get(['lat', 'lng', 'accuracy_m', 'battery', 'recorded_at']),
            'job_events' => \App\Models\JobStatusLog::where('user_id', $user->id)->whereDate('created_at', $day)
                ->whereNotNull('lat')->with('job:id,number')->orderBy('id')->get(['service_job_id', 'to_status', 'lat', 'lng', 'created_at']),
        ]);
    }

    public function attendance(Request $request): JsonResponse
    {
        $day = $request->date('date') ?? today();
        $staff = $this->technicians($request)->orderBy('name')->get(['id', 'name', 'phone']);
        $rows = Attendance::whereIn('user_id', $staff->pluck('id'))->whereDate('date', $day)->get()->keyBy('user_id');

        return response()->json([
            'date' => $day->toDateString(),
            'present' => $rows->count(),
            'absent' => $staff->count() - $rows->count(),
            'rows' => $staff->map(fn ($s) => ['user' => $s, 'attendance' => $rows->get($s->id)]),
        ]);
    }

    public function expenses(Request $request): JsonResponse
    {
        $q = Expense::query()->visibleTo($request->user())->with(['user:id,name', 'reviewer:id,name', 'job:id,number']);
        foreach (['status', 'user_id', 'category'] as $f) {
            if ($request->filled($f)) {
                $q->where($f, $request->input($f));
            }
        }
        if ($request->filled('from')) { $q->whereDate('date', '>=', $request->date('from')); }
        if ($request->filled('to')) { $q->whereDate('date', '<=', $request->date('to')); }

        $totals = (clone $q)->reorder()->selectRaw('status, sum(amount) as total, count(*) as n')->groupBy('status')->get()->keyBy('status');

        return response()->json(['totals' => $totals] + $q->latest('date')->latest('id')->paginate($this->perPage($request))->toArray());
    }

    public function reviewExpense(Request $request, Expense $expense): JsonResponse
    {
        $this->ensureVisible($expense, $request->user());
        $data = $request->validate([
            'status' => ['required', Rule::in(['approved', 'rejected'])],
            'note' => ['nullable', 'string', 'max:255', 'required_if:status,rejected'],
        ]);
        abort_unless($expense->status === 'pending', 422, 'Expense was already reviewed.');
        abort_if($expense->user_id === $request->user()->id, 403, 'You cannot approve your own expense.');

        $expense->update(['status' => $data['status'], 'review_note' => $data['note'] ?? null, 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);

        return response()->json($expense);
    }
}
