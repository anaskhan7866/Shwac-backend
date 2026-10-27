<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\NotificationLog;
use App\Services\Messaging\Notifier;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Free WhatsApp: the office "outbox" of prepared messages. Each row has a wa.me link;
 * tapping it opens WhatsApp / WhatsApp Web with the text filled in, staff press send,
 * then mark it sent here.
 */
class WhatsAppController extends Controller
{
    public function outbox(Request $request): JsonResponse
    {
        $user = $request->user();
        $status = $request->input('status', 'ready');
        $q = NotificationLog::query()->whereNotNull('link')->with(['notifiable', 'creator:id,name', 'sender:id,name']);
        if ($status !== 'all') {
            $q->where('status', $status);
        }
        if (! $user->canSeeAllBranches()) {
            $q->where('branch_id', $user->branch_id);
        }
        if ($request->filled('event')) {
            $q->where('event', $request->input('event'));
        }

        $page = $q->latest('id')->paginate($this->perPage($request));
        $page->getCollection()->transform(fn (NotificationLog $l) => $l->share() + [
            'status' => $l->status,
            'created_at' => $l->created_at?->toIso8601String(),
            'created_by' => $l->creator?->name,
            'sent_by' => $l->sender?->name,
            'subject' => $l->notifiable ? class_basename($l->notifiable).' '.($l->notifiable->number ?? '#'.$l->notifiable->getKey()) : null,
        ]);

        return response()->json($page);
    }

    public function markSent(Request $request, NotificationLog $log): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->canSeeAllBranches() || (int) $log->branch_id === (int) $user->branch_id, 404);

        return response()->json($log->markSent($user)->share());
    }

    /** Free-form message to a customer (opens WhatsApp with the text). */
    public function compose(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'message' => ['required', 'string', 'max:2000'],
        ]);
        $customer = Customer::findOrFail($data['customer_id']);
        $this->ensureVisible($customer, $request->user());

        $log = NotificationLog::create([
            'channel' => 'whatsapp', 'branch_id' => $customer->branch_id, 'event' => 'custom',
            'to' => $customer->whatsappNumber(), 'notifiable_type' => $customer->getMorphClass(), 'notifiable_id' => $customer->id,
            'status' => 'ready', 'message' => $data['message'], 'link' => Notifier::waLink($customer->whatsappNumber(), $data['message']),
            'created_by' => $request->user()->id,
        ]);

        return response()->json($log->share(), 201);
    }

    /** Payment reminder for an unpaid invoice (with the public invoice / UPI link). */
    public function reminder(Request $request, Invoice $invoice, Notifier $notifier): JsonResponse
    {
        $this->ensureVisible($invoice, $request->user());
        abort_unless($invoice->isPayable(), 422, 'This invoice has nothing due.');

        $log = $notifier->notifyCustomer($invoice->customer, 'payment_reminder', [
            'invoice_number' => $invoice->number,
            'amount' => Money::format($invoice->balance_due),
            'due_date' => $invoice->due_date?->format('d M Y'),
            'link' => $invoice->publicUrl(),
        ], $invoice);

        return response()->json($log?->share() ?? ['skipped' => true, 'reason' => $log?->error], 201);
    }
}
