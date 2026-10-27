<?php

namespace App\Http\Controllers\Api\V1\Technician;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Expense;
use App\Models\LocationPing;
use App\Models\Product;
use App\Models\ServiceJob;
use App\Models\StockLevel;
use App\Services\Inventory\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Technician app: day summary, attendance, location, expenses, van stock, barcode lookup.
 */
class FieldController extends Controller
{
    public function __construct(private InventoryService $inventory) {}

    /** Everything the app's home screen needs in one call. */
    public function today(Request $request): JsonResponse
    {
        $u = $request->user();
        $van = $this->inventory->vanFor($u);

        return response()->json([
            'user' => $u->only(['id', 'name', 'phone', 'branch_id']),
            'location_consent' => $u->location_consent_at !== null,
            'attendance' => Attendance::where('user_id', $u->id)->whereDate('date', today())->first(),
            'jobs' => [
                'active' => ServiceJob::where('technician_id', $u->id)->whereIn('status', ServiceJob::ACTIVE_STATUSES)->count(),
                'completed_today' => ServiceJob::where('technician_id', $u->id)->whereDate('completed_at', today())->count(),
            ],
            'expenses_today' => (string) Expense::where('user_id', $u->id)->whereDate('date', today())->where('status', '!=', 'rejected')->sum('amount'),
            'van' => ['id' => $van->id, 'name' => $van->name, 'low_items' => \App\Models\StockAlert::where('warehouse_id', $van->id)->where('status', 'open')->count()],
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function consent(Request $request): JsonResponse
    {
        $request->validate(['accepted' => ['required', 'accepted']]);
        $request->user()->update(['location_consent_at' => now()]);

        return response()->json(['location_consent' => true]);
    }

    // ---- Attendance ------------------------------------------------------------

    public function checkIn(Request $request): JsonResponse
    {
        $data = $this->validateGps($request) + $request->validate(['address' => ['nullable', 'string', 'max:255']]);
        $u = $request->user();
        abort_unless($u->location_consent_at, 422, 'Please accept location tracking first.');

        $att = Attendance::where('user_id', $u->id)->whereDate('date', today())->first()
            ?? Attendance::create([
                'user_id' => $u->id, 'date' => today()->toDateString(), 'branch_id' => $u->branch_id, 'check_in_at' => now(),
                'check_in_lat' => $data['lat'], 'check_in_lng' => $data['lng'], 'check_in_address' => $data['address'] ?? null,
            ]);
        $this->touchLocation($u, $data['lat'], $data['lng']);

        return response()->json($att, $att->wasRecentlyCreated ? 201 : 200);
    }

    public function checkOut(Request $request): JsonResponse
    {
        $data = $this->validateGps($request);
        $u = $request->user();
        $att = Attendance::where('user_id', $u->id)->whereDate('date', today())->first();
        abort_unless($att, 422, 'You have not checked in today.');
        abort_if($att->check_out_at, 422, 'Already checked out.');

        $att->update([
            'check_out_at' => now(), 'check_out_lat' => $data['lat'], 'check_out_lng' => $data['lng'],
            'worked_minutes' => (int) $att->check_in_at->diffInMinutes(now()),
        ]);

        return response()->json($att);
    }

    public function attendanceHistory(Request $request): JsonResponse
    {
        $month = $request->date('month') ?? today();

        return response()->json(
            Attendance::where('user_id', $request->user()->id)
                ->whereBetween('date', [$month->copy()->startOfMonth()->toDateString(), $month->copy()->endOfMonth()->toDateString()])
                ->orderBy('date')->get()
        );
    }

    /** Batch of GPS points (the app buffers them, including while offline). */
    public function location(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pings' => ['required', 'array', 'min:1', 'max:500'],
            'pings.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'pings.*.lng' => ['required', 'numeric', 'between:-180,180'],
            'pings.*.accuracy' => ['nullable', 'numeric', 'min:0'],
            'pings.*.battery' => ['nullable', 'integer', 'between:0,100'],
            'pings.*.recorded_at' => ['required', 'date', 'before_or_equal:+5 minutes', 'after:-3 days'],
        ]);
        $u = $request->user();
        abort_unless($u->location_consent_at, 422, 'Location tracking has not been accepted.');

        $rows = collect($data['pings'])->sortBy('recorded_at')->map(fn ($p) => [
            'user_id' => $u->id,
            'lat' => $p['lat'], 'lng' => $p['lng'],
            'accuracy_m' => isset($p['accuracy']) ? min((int) $p['accuracy'], 65535) : null,
            'battery' => $p['battery'] ?? null,
            'recorded_at' => \Illuminate\Support\Carbon::parse($p['recorded_at'])->setTimezone(config('app.timezone')),
            'created_at' => now(),
        ])->values();
        LocationPing::insert($rows->all());
        $last = $rows->last();
        $this->touchLocation($u, (float) $last['lat'], (float) $last['lng'], $last['recorded_at']);

        return response()->json(['stored' => $rows->count()], 201);
    }

    // ---- Expenses --------------------------------------------------------------

    public function expenses(Request $request): JsonResponse
    {
        return response()->json(
            Expense::where('user_id', $request->user()->id)->latest('date')->latest('id')->paginate($this->perPage($request))
        );
    }

    public function storeExpense(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['nullable', 'date', 'before_or_equal:today', 'after:-31 days'],
            'category' => ['required', Rule::in(Expense::CATEGORIES)],
            'amount' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'description' => ['nullable', 'string', 'max:255'],
            'service_job_id' => ['nullable', 'integer'],
            'receipt' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ]);
        $u = $request->user();
        if (! empty($data['service_job_id'])) {
            abort_unless(ServiceJob::whereKey($data['service_job_id'])->where('technician_id', $u->id)->exists(), 422, 'Job is not yours.');
        }

        $expense = Expense::create([
            'user_id' => $u->id,
            'branch_id' => $u->branch_id,
            'service_job_id' => $data['service_job_id'] ?? null,
            'date' => $data['date'] ?? today()->toDateString(),
            'category' => $data['category'],
            'amount' => $data['amount'],
            'description' => $data['description'] ?? null,
            'receipt_path' => $request->hasFile('receipt') ? $request->file('receipt')->store('receipts/'.now()->format('Y/m'), 'public') : null,
        ]);

        return response()->json($expense, 201);
    }

    // ---- Van stock & parts -----------------------------------------------------

    public function van(Request $request): JsonResponse
    {
        $van = $this->inventory->vanFor($request->user());

        return response()->json([
            'warehouse' => $van,
            'items' => StockLevel::where('warehouse_id', $van->id)->where('quantity', '>', 0)
                ->with('product:id,sku,name,unit,sale_price,tax_rate,hsn,barcode,van_min_level')
                ->get()->sortBy('product.name')->values(),
        ]);
    }

    /** Scan result → product with the quantity in this technician's van. */
    public function lookup(Request $request): JsonResponse
    {
        $code = $request->validate(['code' => ['required', 'string', 'max:64']])['code'];
        $product = Product::where('is_active', true)->where(fn ($q) => $q->where('barcode', $code)->orWhere('sku', $code))->first();
        abort_unless($product, 404, 'No spare part found for code '.$code.'.');
        $van = $this->inventory->vanFor($request->user());

        return response()->json($product->only(['id', 'sku', 'name', 'unit', 'sale_price', 'tax_rate', 'hsn', 'barcode']) + [
            'in_van' => $this->inventory->quantity($product, $van),
        ]);
    }

    // ---------------------------------------------------------------------------

    private function validateGps(Request $request): array
    {
        return $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);
    }

    private function touchLocation($user, float $lat, float $lng, $at = null): void
    {
        DB::table('users')->where('id', $user->id)->update(['last_lat' => $lat, 'last_lng' => $lng, 'last_seen_at' => $at ?? now()]);
    }
}
