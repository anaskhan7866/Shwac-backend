<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Complaint;
use App\Models\Customer;
use App\Models\User;
use App\Services\Operations\ComplaintService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ComplaintController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = Complaint::query()->visibleTo($request->user())
            ->with(['customer:id,name,phone', 'assignee:id,name']);

        foreach (['status', 'priority', 'branch_id', 'assigned_to', 'category'] as $f) {
            if ($request->filled($f)) {
                $q->whereIn($f, (array) $request->input($f));
            }
        }
        if ($request->boolean('open')) { $q->open(); }
        if ($request->boolean('breached')) { $q->where('sla_breached', true); }
        if ($request->boolean('due_soon')) {
            $q->open()->where('sla_resolution_due_at', '<=', now()->addHours(2));
        }
        if ($s = $request->string('q')->trim()->value()) {
            $q->where(fn ($w) => $w->where('number', $s)->orWhere('subject', 'like', "%{$s}%")
                ->orWhereHas('customer', fn ($c) => $c->where('phone', 'like', "%{$s}%")));
        }

        // Most urgent first: breached, then nearest resolution deadline.
        $page = $q->orderByDesc('sla_breached')->orderBy('sla_resolution_due_at')->paginate($this->perPage($request));
        $page->getCollection()->each(fn (Complaint $c) => $c->setAttribute('sla_minutes_left', $c->slaMinutesLeft()));

        return response()->json($page);
    }

    public function store(Request $request, ComplaintService $complaints): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'customer_address_id' => ['nullable', 'integer'],
            'customer_product_id' => ['nullable', 'integer'],
            'category' => ['nullable', 'string', 'max:40'],
            'priority' => ['nullable', Rule::in(Complaint::PRIORITIES)],
            'channel' => ['nullable', Rule::in(['phone', 'whatsapp', 'walk_in', 'admin', 'email'])],
            'subject' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],
            'auto_assign' => ['sometimes', 'boolean'],
        ]);
        $customer = Customer::findOrFail($data['customer_id']);
        $this->ensureVisible($customer, $request->user());
        $this->assertBelongs($customer, $data);

        $complaint = $complaints->create($customer, $data + ['channel' => $data['channel'] ?? 'phone'], $request->user(), $data['auto_assign'] ?? true);

        return response()->json($complaint->load('assignee:id,name', 'jobs'), 201);
    }

    public function show(Request $request, Complaint $complaint): JsonResponse
    {
        $this->ensureVisible($complaint, $request->user());
        $complaint->load(['customer', 'address', 'customerProduct', 'assignee:id,name,phone', 'updates.user:id,name', 'jobs.technician:id,name']);
        $complaint->setAttribute('sla_minutes_left', $complaint->slaMinutesLeft());

        return response()->json($complaint);
    }

    public function assign(Request $request, Complaint $complaint, ComplaintService $complaints): JsonResponse
    {
        $this->ensureVisible($complaint, $request->user());
        $data = $request->validate(['technician_id' => ['required', 'exists:users,id'], 'note' => ['nullable', 'string', 'max:255']]);

        return response()->json($complaints->assign($complaint, User::findOrFail($data['technician_id']), $request->user(), $data['note'] ?? null));
    }

    public function updateStatus(Request $request, Complaint $complaint, ComplaintService $complaints): JsonResponse
    {
        $this->ensureVisible($complaint, $request->user());
        $data = $request->validate([
            'status' => ['required', Rule::in(Complaint::STATUSES)],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json($complaints->changeStatus($complaint, $data['status'], $request->user(), $data['note'] ?? null));
    }

    public function update(Request $request, Complaint $complaint): JsonResponse
    {
        $this->ensureVisible($complaint, $request->user());
        $complaint->update($request->validate([
            'priority' => ['sometimes', Rule::in(Complaint::PRIORITIES)],
            'category' => ['sometimes', 'string', 'max:40'],
            'subject' => ['sometimes', 'string', 'max:160'],
        ]));

        return response()->json($complaint);
    }

    public function comment(Request $request, Complaint $complaint, ComplaintService $complaints): JsonResponse
    {
        $this->ensureVisible($complaint, $request->user());
        $data = $request->validate(['body' => ['required', 'string', 'max:5000'], 'is_internal' => ['sometimes', 'boolean']]);
        $complaints->comment($complaint, $request->user(), $data['body'], $data['is_internal'] ?? false);

        return response()->json($complaint->load('updates.user:id,name'), 201);
    }

    private function assertBelongs(Customer $customer, array $data): void
    {
        if (! empty($data['customer_address_id']) && ! $customer->addresses()->whereKey($data['customer_address_id'])->exists()) {
            abort(422, 'Address does not belong to this customer.');
        }
        if (! empty($data['customer_product_id']) && ! $customer->products()->whereKey($data['customer_product_id'])->exists()) {
            abort(422, 'Product does not belong to this customer.');
        }
    }
}
