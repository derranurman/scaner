<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\PlatformDeduction;
use App\Models\Product;
use App\Models\Variant;
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

        $products = Product::with('variants')->where('is_active', true)->get();

        return view('orders.create', compact('platforms', 'products'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateOrder($request);

        $order = Order::create($data);

        // Save order items based on kelengkapan selection
        $this->saveOrderItems($request, $order);

        return redirect()->route('orders.index')
            ->with('success', "Pesanan {$order->resi_number} berhasil dibuat.");
    }

    public function edit(Order $order): View
    {
        $order->load('items.variant.product');

        $platforms = PlatformDeduction::where('is_active', true)
            ->orderBy('platform_name')
            ->get(['id', 'platform_name']);

        $products = Product::with('variants')->where('is_active', true)->get();

        return view('orders.edit', compact('order', 'platforms', 'products'));
    }

    public function update(Request $request, Order $order): RedirectResponse
    {
        $data = $this->validateOrder($request, $order);

        $order->update($data);

        // Re-save order items based on kelengkapan selection
        $this->saveOrderItems($request, $order);

        return redirect()->route('orders.index')
            ->with('success', "Pesanan {$order->resi_number} berhasil diperbarui.");
    }

    public function destroy(Order $order): RedirectResponse
    {
        if ($order->status === Order::STATUS_PACKED) {
            return back()->with('error', 'Pesanan yang sudah di-packing tidak bisa dihapus.');
        }

        $order->delete();

        return redirect()->route('orders.index')->with('success', 'Pesanan dihapus.');
    }

    /**
     * Update status pesanan secara inline (tanpa hapus).
     */
    public function updateStatus(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in([
                Order::STATUS_PENDING,
                Order::STATUS_PACKED,
                Order::STATUS_CANCELLED,
            ])],
        ]);

        $order->update(['status' => $data['status']]);

        return back()->with('success', "Status pesanan {$order->resi_number} diubah menjadi {$data['status']}.");
    }

    /**
     * Update inline Host Live & Platform dari halaman Pesanan.
     */
    public function updateMeta(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate([
            'host_live' => ['nullable', 'string', 'max:100'],
            'platform_deduction_id' => ['nullable', 'integer', 'exists:platform_deductions,id'],
        ]);

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

    /**
     * Simpan order items berdasarkan pilihan kelengkapan.
     */
    private function saveOrderItems(Request $request, Order $order): void
    {
        $kelengkapan = $request->input('kelengkapan');
        if (! $kelengkapan) {
            return;
        }

        $kelengkapanLabels = [
            '1' => 'Stir Saja',
            '2' => 'Stir + Boskit',
            '3' => 'Stir + Boskit + Spion',
        ];

        $qty = max(1, (int) $request->input('item_quantity', 1));

        // Collect variant IDs based on kelengkapan level
        $variantIds = [];
        if (in_array($kelengkapan, ['1', '2', '3'])) {
            $variantIds[] = $request->input('variant_stir');
        }
        if (in_array($kelengkapan, ['2', '3'])) {
            $variantIds[] = $request->input('variant_boskit');
        }
        if ($kelengkapan === '3') {
            $variantIds[] = $request->input('variant_spion');
        }

        // Filter out empty values
        $variantIds = array_filter($variantIds);

        if (empty($variantIds)) {
            return;
        }

        // Remove existing items (for update scenario)
        $order->items()->delete();

        // Calculate total harga modal
        $totalHargaModal = 0;

        foreach ($variantIds as $variantId) {
            $variant = Variant::with('product')->find($variantId);
            if (! $variant) {
                continue;
            }

            $purchasePrice = (float) ($variant->product->purchase_price ?? 0);
            $totalHargaModal += $purchasePrice * $qty;

            $order->items()->create([
                'variant_id' => $variant->id,
                'product_name' => $variant->product->name,
                'variant_name' => $variant->name,
                'sku' => $variant->sku,
                'kelengkapan' => $kelengkapanLabels[$kelengkapan] ?? null,
                'harga_modal' => $purchasePrice * $qty,
                'quantity' => $qty,
            ]);
        }
    }
}
