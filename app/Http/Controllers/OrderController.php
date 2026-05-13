<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\PlatformDeduction;
use App\Services\OrderMetricsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OrderController extends Controller
{
    public function __construct(private OrderMetricsService $metrics)
    {
    }

    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $status = $request->query('status');
        $date = $request->query('date');

        $orders = Order::with(['items.variant.product', 'packedBy', 'platformDeduction'])
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($sub) use ($q) {
                    $sub->where('resi_number', 'like', "%{$q}%")
                        ->orWhere('tiktok_order_id', 'like', "%{$q}%")
                        ->orWhere('buyer_name', 'like', "%{$q}%")
                        ->orWhere('host_live', 'like', "%{$q}%")
                        ->orWhere('sender_name', 'like', "%{$q}%");
                });
            })
            ->when($status, fn ($qq) => $qq->where('status', $status))
            ->when($date, fn ($qq) => $qq->whereDate('order_date', $date))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        // Hitung metrik per order sekarang, kirim sebagai parallel array ke view.
        $metrics = [];
        foreach ($orders as $order) {
            $metrics[$order->id] = $this->metrics->compute($order);
        }

        $platforms = PlatformDeduction::where('is_active', true)
            ->orderBy('platform_name')
            ->get(['id', 'platform_name']);

        // Index awal untuk kolom "No" — mulai dari halaman saat ini
        $startNo = ($orders->currentPage() - 1) * $orders->perPage() + 1;

        return view('orders.index', compact(
            'orders',
            'metrics',
            'platforms',
            'startNo',
            'q',
            'status',
            'date',
        ));
    }

    public function show(Order $order): View
    {
        $order->load(['items.variant.product', 'packedBy', 'platformDeduction']);

        return view('orders.show', [
            'order' => $order,
            'metric' => $this->metrics->compute($order),
        ]);
    }

    public function create(): View
    {
        $platforms = PlatformDeduction::where('is_active', true)
            ->orderBy('platform_name')
            ->get(['id', 'platform_name']);

        return view('orders.create', compact('platforms'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateOrder($request);

        $order = Order::create($data);

        return redirect()->route('orders.index')
            ->with('success', "Pesanan {$order->resi_number} berhasil dibuat.");
    }

    public function edit(Order $order): View
    {
        $platforms = PlatformDeduction::where('is_active', true)
            ->orderBy('platform_name')
            ->get(['id', 'platform_name']);

        return view('orders.edit', compact('order', 'platforms'));
    }

    public function update(Request $request, Order $order): RedirectResponse
    {
        $data = $this->validateOrder($request, $order);

        $order->update($data);

        return redirect()->route('orders.index')
            ->with('success', "Pesanan {$order->resi_number} berhasil diperbarui.");
    }

    public function destroy(Order $order): RedirectResponse
    {
        // Admin bisa hapus pesanan apapun, termasuk yang sudah packed.
        $resi = $order->resi_number;
        $order->delete();

        return redirect()->route('orders.index')
            ->with('success', "Pesanan {$resi} dihapus.");
    }

    /**
     * Update inline dari halaman Pesanan untuk field-field yang bisa di-edit
     * langsung di tabel (Host Live, Platform, Pembeli, No. HP).
     *
     * Semua field optional — hanya yang ada di request yang di-update.
     */
    public function updateMeta(Request $request, Order $order): RedirectResponse
    {
        $validated = $request->validate([
            'host_live' => ['nullable', 'string', 'max:100'],
            'platform_deduction_id' => ['nullable', 'integer', 'exists:platform_deductions,id'],
            'buyer_name' => ['nullable', 'string', 'max:150'],
            'buyer_phone' => ['nullable', 'string', 'max:30'],
        ]);

        // Hanya update field yang benar-benar hadir di request (supaya form
        // inline yang hanya kirim 1-2 field tidak meng-null-kan field lain).
        $data = array_intersect_key($validated, $request->all());

        $order->update($data);

        return back()->with('success', "Pesanan {$order->resi_number} diperbarui.");
    }

    /**
     * Shared validation untuk create & update order.
     *
     * @return array<string, mixed>
     */
    private function validateOrder(Request $request, ?Order $existing = null): array
    {
        $resiRule = [
            'required', 'string', 'max:32',
            Rule::unique('orders', 'resi_number')->ignore($existing?->id),
        ];

        return $request->validate([
            'resi_number' => $resiRule,
            'tiktok_order_id' => ['nullable', 'string', 'max:64'],
            'courier' => ['nullable', 'string', 'max:20'],
            'buyer_name' => ['nullable', 'string', 'max:150'],
            'buyer_phone' => ['nullable', 'string', 'max:30'],
            'sender_name' => ['nullable', 'string', 'max:150'],
            'host_live' => ['nullable', 'string', 'max:100'],
            'platform_deduction_id' => ['nullable', 'integer', 'exists:platform_deductions,id'],
            'shipping_address' => ['nullable', 'string'],
            'status' => ['nullable', Rule::in([
                Order::STATUS_PENDING,
                Order::STATUS_PACKED,
                Order::STATUS_CANCELLED,
            ])],
            'order_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);
    }
}
