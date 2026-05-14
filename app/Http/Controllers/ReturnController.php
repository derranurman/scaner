<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\OrderMetricsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReturnController extends Controller
{
    public function __construct(private OrderMetricsService $metrics)
    {
    }

    /**
     * Kelola Return — tampilkan pesanan yang statusnya "return".
     */
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        $orders = Order::with(['items.variant.product', 'platformDeduction'])
            ->where('status', Order::STATUS_RETURN)
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($sub) use ($q) {
                    $sub->where('resi_number', 'like', "%{$q}%")
                        ->orWhere('buyer_name', 'like', "%{$q}%")
                        ->orWhere('tiktok_order_id', 'like', "%{$q}%");
                });
            })
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        $metrics = [];
        foreach ($orders as $order) {
            $metrics[$order->id] = $this->metrics->compute($order);
        }

        return view('returns.index', compact('orders', 'metrics', 'q'));
    }

    /**
     * Tandai pesanan sebagai return dari halaman Kelola Return.
     */
    public function markReturn(Request $request): RedirectResponse
    {
        $request->validate([
            'resi_number' => ['required', 'string'],
        ]);

        $order = Order::where('resi_number', $request->input('resi_number'))->first();

        if (! $order) {
            return back()->with('error', 'Pesanan dengan resi tersebut tidak ditemukan.');
        }

        $order->update([
            'status' => Order::STATUS_RETURN,
            'notes' => trim($order->notes . "\n[RETURN] " . ($request->input('reason') ?? 'Tanpa alasan') . ' — ' . now()->format('d/m/Y H:i')),
        ]);

        return back()->with('success', "Pesanan {$order->resi_number} ditandai sebagai Return.");
    }

    /**
     * Kembalikan status dari return ke status sebelumnya (pending).
     */
    public function undoReturn(Order $order): RedirectResponse
    {
        if ($order->status !== Order::STATUS_RETURN) {
            return back()->with('error', 'Pesanan ini tidak dalam status Return.');
        }

        $order->update(['status' => Order::STATUS_PENDING]);

        return back()->with('success', "Pesanan {$order->resi_number} dikembalikan ke Pending.");
    }
}
