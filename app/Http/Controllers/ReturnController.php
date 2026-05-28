<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\OrderMetricsService;
use App\Services\StockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReturnController extends Controller
{
    public function __construct(
        private OrderMetricsService $metrics,
        private StockService $stockService,
    ) {
    }

    /**
     * Kelola Return — tampilkan pesanan yang statusnya "return"
     * (yaitu yang masih perlu ditangani / belum diterima kembali).
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
            // returned_at = penanda permanen pesanan pernah di-return.
            // Hanya di-set jika belum pernah ada (supaya tanggal awal tetap).
            'returned_at' => $order->returned_at ?? now(),
            'notes' => trim($order->notes . "\n[RETURN] " . ($request->input('reason') ?? 'Tanpa alasan') . ' — ' . now()->format('d/m/Y H:i')),
        ]);

        return back()->with('success', "Pesanan {$order->resi_number} ditandai sebagai Return.");
    }

    /**
     * Batal return: kembalikan pesanan ke status pending dan
     * hapus penanda returned_at (return jadi tidak pernah terjadi).
     */
    public function undoReturn(Order $order): RedirectResponse
    {
        if ($order->status !== Order::STATUS_RETURN) {
            return back()->with('error', 'Pesanan ini tidak dalam status Return.');
        }

        $order->update([
            'status' => Order::STATUS_PENDING,
            'returned_at' => null,
        ]);

        return back()->with('success', "Pesanan {$order->resi_number} dikembalikan ke Pending.");
    }

    /**
     * Tandai bahwa barang return SUDAH DITERIMA kembali oleh toko.
     * Otomatis menambah stok untuk setiap item, lalu set status jadi
     * "selesai_return". `returned_at` SENGAJA TIDAK DIHAPUS supaya
     * pesanan tetap muncul di Laporan Return sebagai histori.
     *
     * Catatan: status "selesai_return" terpisah dari
     * "selesai_bulan_kemarin" (untuk pesanan lintas bulan yang sudah
     * tutup buku) supaya histori return bisa dibedakan dengan jelas
     * dari pesanan biasa yang sudah selesai.
     */
    public function receiveItems(Request $request, Order $order): RedirectResponse
    {
        if ($order->status !== Order::STATUS_RETURN) {
            return back()->with('error', 'Pesanan ini tidak dalam status Return.');
        }

        $order->load('items.variant');

        $totalRestocked = 0;
        $skipped = [];

        foreach ($order->items as $item) {
            if (! $item->variant) {
                $skipped[] = $item->sku ?? $item->product_name;
                continue;
            }

            $this->stockService->adjust(
                variant: $item->variant,
                qty: (int) $item->quantity,
                type: \App\Models\StockMovement::TYPE_IN,
                userId: auth()->id(),
                orderId: $order->id,
                reference: "Return diterima — Resi {$order->resi_number}",
            );

            $totalRestocked += (int) $item->quantity;
        }

        // Pindahkan status, TAPI biarkan returned_at agar tetap tercatat
        // di Laporan Return sebagai histori barang yang pernah di-return.
        //
        // Safety net: jika `returned_at` null (mis. order ini tadinya
        // di-set status='return' via dropdown inline tanpa lewat markReturn),
        // set sekarang supaya tetap muncul di Laporan Return.
        $order->update([
            'status' => Order::STATUS_SELESAI_RETURN,
            'returned_at' => $order->returned_at ?? now(),
            'notes' => trim($order->notes . "\n[BARANG DITERIMA] " . now()->format('d/m/Y H:i') . " — {$totalRestocked} unit dikembalikan ke stok"),
        ]);

        $msg = "Barang return resi {$order->resi_number} diterima. {$totalRestocked} unit dikembalikan ke stok.";
        if (! empty($skipped)) {
            $msg .= ' (Item tanpa variant terkait dilewati: ' . implode(', ', $skipped) . ')';
        }

        return redirect()->route('returns.index')->with('success', $msg);
    }
}
