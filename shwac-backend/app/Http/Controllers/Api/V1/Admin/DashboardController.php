<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Complaint;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ServiceJob;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Global dashboard. Branch-scoped for branch staff; ?branch_id= filters for all-branch users.
 * Expenses arrive with Phase 2 (technician expenses) and Phase 3 (ledger).
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $scope = function (Builder $q) use ($user, $request) {
            $q->visibleTo($user);
            if ($request->filled('branch_id') && $user->canSeeAllBranches()) {
                $q->where($q->getModel()->getTable().'.branch_id', $request->integer('branch_id'));
            }

            return $q;
        };

        $today = today();
        $monthStart = $today->copy()->startOfMonth();

        $out = [
            'as_of' => now()->toIso8601String(),
            'bookings' => [
                'today' => $scope(Booking::query())->whereDate('created_at', $today)->count(),
                'scheduled_today' => $scope(Booking::query())->whereDate('scheduled_date', $today)->whereNotIn('status', ['cancelled'])->count(),
                'pending' => $scope(Booking::query())->where('status', 'pending')->count(),
            ],
            'jobs' => [
                'live' => $scope(ServiceJob::query())->whereIn('status', ServiceJob::ACTIVE_STATUSES)->count(),
                'unassigned' => $scope(ServiceJob::query())->where('status', 'pending')->whereNull('technician_id')->count(),
                'completed_today' => $scope(ServiceJob::query())->whereDate('completed_at', $today)->count(),
                'by_status' => $scope(ServiceJob::query())->whereNotIn('status', ['completed', 'cancelled'])
                    ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            ],
            'complaints' => [
                'open' => $scope(Complaint::query())->open()->count(),
                'sla_breached' => $scope(Complaint::query())->open()->where('sla_breached', true)->count(),
                'due_in_2h' => $scope(Complaint::query())->open()->where('sla_breached', false)
                    ->where('sla_resolution_due_at', '<=', now()->addHours(2))->count(),
                'resolved_today' => $scope(Complaint::query())->whereDate('resolved_at', $today)->count(),
            ],
        ];

        $techQ = \App\Models\User::where('user_type', 'technician')->where('is_active', true)
            ->when(! $user->canSeeAllBranches(), fn ($q) => $q->where('branch_id', $user->branch_id))
            ->when($request->filled('branch_id') && $user->canSeeAllBranches(), fn ($q) => $q->where('branch_id', $request->integer('branch_id')));
        $techIds = $techQ->pluck('id');
        $out['field'] = [
            'technicians' => $techIds->count(),
            'on_duty' => \App\Models\Attendance::whereIn('user_id', $techIds)->whereDate('date', $today)->whereNull('check_out_at')->count(),
            'expenses_pending' => $scope(\App\Models\Expense::query())->where('status', 'pending')->count(),
        ];
        $out['todo'] = [
            'whatsapp_to_send' => \App\Models\NotificationLog::toSend()
                ->when(! $user->canSeeAllBranches(), fn ($q) => $q->where('branch_id', $user->branch_id))->count(),
            'payments_to_verify' => $scope(Payment::query())->where('status', 'pending_verification')->count(),
        ];
        $out['inventory'] = [
            'low_stock_alerts' => \App\Models\StockAlert::where('status', 'open')
                ->whereIn('warehouse_id', $scope(\App\Models\Warehouse::query())->pluck('id'))->count(),
        ];

        if ($user->hasPermission('reports.finance')) {
            $paid = fn () => $scope(Payment::query())->where('status', 'success');
            $out['finance'] = [
                'collected_today' => Money::fromPaise(Money::toPaise($paid()->whereDate('paid_at', $today)->sum('amount'))),
                'collected_month' => Money::fromPaise(Money::toPaise($paid()->where('paid_at', '>=', $monthStart)->sum('amount'))),
                'invoiced_month' => Money::fromPaise(Money::toPaise($scope(Invoice::query())->whereNotIn('status', ['draft', 'cancelled'])->where('invoice_date', '>=', $monthStart->toDateString())->sum('total'))),
                'receivables' => Money::fromPaise(Money::toPaise($scope(Invoice::query())->whereIn('status', ['issued', 'partially_paid'])->sum('balance_due'))),
                'overdue' => Money::fromPaise(Money::toPaise($scope(Invoice::query())->whereIn('status', ['issued', 'partially_paid'])->whereDate('due_date', '<', $today)->sum('balance_due'))),
                'collections_by_method' => $paid()->where('paid_at', '>=', $monthStart)->selectRaw('method, sum(amount) as total')->groupBy('method')->pluck('total', 'method'),
                'expenses_month' => Money::fromPaise(Money::toPaise($scope(\App\Models\Expense::query())->where('status', 'approved')->where('date', '>=', $monthStart->toDateString())->sum('amount'))),
            ];
        }

        return response()->json($out);
    }
}
