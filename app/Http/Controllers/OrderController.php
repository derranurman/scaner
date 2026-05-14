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
use Symfony\Component\HttpFoundation\StreamedResponse;

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
            ->when($status === 'selesai_bulan_kemarin', function ($qq) {
                // Selesai bulan kemarin = status 'selesai' dan updated_at di bulan kemarin
                $prev = now()->subMonthNoOverflow();
                $qq->where('status', Order::STATUS_SELESAI)
                    ->whereYear('updated_at', $prev->year)
                    ->whereMonth('updated_at', $prev->month);
            })
            ->when($status && $status !== 'selesai_bulan_kemarin', fn ($qq) => $qq->where('status', $status))
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

    /**
     * Export semua data tabel pesanan ke CSV (dibuka di Excel).
     * Mengikuti filter yang sama dengan index().
     */
    public function export(Request $request): StreamedResponse
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
            ->when($status === 'selesai_bulan_kemarin', function ($qq) {
                $prev = now()->subMonthNoOverflow();
                $qq->where('status', Order::STATUS_SELESAI)
                    ->whereYear('updated_at', $prev->year)
                    ->whereMonth('updated_at', $prev->month);
            })
            ->when($status && $status !== 'selesai_bulan_kemarin', fn ($qq) => $qq->where('status', $status))
            ->when($date, fn ($qq) => $qq->whereDate('order_date', $date))
            ->latest('id')
            ->get();

        $filename = 'pesanan-' . now()->format('Y-m-d-His') . '.csv';

        return response()->streamDownload(function () use ($orders) {
            $handle = fopen('php://output', 'w');

            // BOM agar Excel mengenali UTF-8 dengan benar.
            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

            // Header — persis sama dengan kolom di halaman /orders.
            fputcsv($handle, [
                'No',
                'Resi',
                'Order ID',
                'Host Live',
                'Platform',
                'Pengirim',
                'Pembeli',
                'No. HP',
                'SKU',
                'Harga Jual',
                'Total Jual',
                'Total Modal',
                'Total Reseller',
                'Ongkir Cargo',
                'Yield',
                'Plastik/Dus',
                'Operasional',
                'ADM (%)',
                'ADM (Rp)',
                'Ongkir Free (%)',
                'Ongkir Free (Rp)',
                'Bulat Max 650Rb',
                'Biaya Layanan (Rp)',
                'Biaya Logistik (Rp)',
                'Pajak (%)',
                'Pajak (Rp)',
                'Profit Kotor',
                '% Profit Kotor',
                'Margin Bisnis',
                '% Margin Bisnis',
                'Margin Live',
                '% Margin Live',
                'Bersih Margin Live',
                'TOTAL POTONGAN APLIKASI',
                'Status',
            ], ';');

            $no = 1;
            foreach ($orders as $order) {
                $m = $this->metrics->compute($order);

                $skus = $order->items->pluck('sku')->filter()->unique()->implode(', ');
                $hargaJual = (float) ($order->items->first()?->variant?->product?->selling_price ?? 0);

                fputcsv($handle, [
                    $no++,
                    $order->resi_number,
                    $order->tiktok_order_id,
                    $order->host_live ?? '—',
                    $order->platformDeduction?->platform_name ?? '—',
                    $order->sender_name ?? '—',
                    $order->buyer_name ?? '—',
                    $order->buyer_phone ?? '—',
                    $skus ?: '—',
                    $hargaJual,
                    $m['total_jual'],
                    $m['total_modal'],
                    $m['total_reseller'],
                    $m['ongkir_cargo'],
                    $m['yield_rp'],
                    $m['plastik_dus'],
                    $m['operasional_rp'],
                    $m['adm_pct'],
                    $m['adm_rp'],
                    $m['ongkir_free_pct'],
                    $m['ongkir_free_rp'],
                    $m['bulat_max'],
                    $m['biaya_layanan'],
                    $m['biaya_logistik'],
                    $m['pajak_pct'],
                    $m['pajak_rp'],
                    $m['profit_kotor'],
                    $m['pct_profit_kotor'],
                    $m['margin_bisnis'],
                    $m['pct_margin_bisnis'],
                    $m['margin_live'],
                    $m['pct_margin_live'],
                    $m['bersih_margin_live'],
                    $m['total_potongan_aplikasi'],
                    ucfirst($order->status),
                ], ';');
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
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
                Order::STATUS_RETURN,
                Order::STATUS_SELESAI,
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
                Order::STATUS_RETURN,
                Order::STATUS_SELESAI,
            ])],
            'order_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);
    }

    /**
     * Simpan order items berdasarkan pilihan kelengkapan.
     *
     * Kelengkapan codes:
     *   1 = Stir Saja
     *   2 = Stir + Boskit
     *   3 = Boskit Saja
     *   4 = Spoiler
     *   5 = Klakson
     *   6 = Stir + Stir
     *   7 = Stir + Stir + Boskit
     *   8 = Stir + Boskit + Boskit
     */
    private function saveOrderItems(Request $request, Order $order): void
    {
        $kelengkapan = $request->input('kelengkapan');
        if (! $kelengkapan) {
            return;
        }

        // Mapping code -> array of request input field names (sinkron dengan form).
        $fieldMap = [
            '1' => ['variant_stir_1'],
            '2' => ['variant_stir_1', 'variant_boskit_1'],
            '3' => ['variant_boskit_1'],
            '4' => ['variant_spoiler'],
            '5' => ['variant_klakson'],
            '6' => ['variant_stir_1', 'variant_stir_2'],
            '7' => ['variant_stir_1', 'variant_stir_2', 'variant_boskit_1'],
            '8' => ['variant_stir_1', 'variant_boskit_1', 'variant_boskit_2'],
        ];

        if (! isset($fieldMap[$kelengkapan])) {
            return;
        }

        $qty = max(1, (int) $request->input('item_quantity', 1));

        // Ambil semua variant ID yang dibutuhkan dari request.
        $variantIds = [];
        foreach ($fieldMap[$kelengkapan] as $fieldName) {
            $id = $request->input($fieldName);
            if ($id) {
                $variantIds[] = $id;
            }
        }

        if (empty($variantIds)) {
            return;
        }

        // Remove existing items (untuk skenario update).
        $order->items()->delete();

        foreach ($variantIds as $variantId) {
            $variant = Variant::with('product')->find($variantId);
            if (! $variant) {
                continue;
            }

            $purchasePrice = (float) ($variant->product->purchase_price ?? 0);

            $order->items()->create([
                'variant_id' => $variant->id,
                'product_name' => $variant->product->name,
                'variant_name' => $variant->name,
                'sku' => $variant->sku,
                // Simpan CODE agar bisa restore dropdown saat edit.
                'kelengkapan' => (string) $kelengkapan,
                'harga_modal' => $purchasePrice * $qty,
                'quantity' => $qty,
            ]);
        }
    }
}
