<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\PlatformDeduction;
use App\Services\OrderMetricsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function destroy(Order $order): RedirectResponse
    {
        if ($order->status === Order::STATUS_PACKED) {
            return back()->with('error', 'Pesanan yang sudah di-packing tidak bisa dihapus.');
        }

        $order->delete();

        return redirect()->route('orders.index')->with('success', 'Pesanan dihapus.');
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
}
