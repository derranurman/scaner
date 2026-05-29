<?php

namespace App\Http\Controllers;

use App\Models\PackingLog;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\View\View;

class PackingReportController extends Controller
{
    public function index(Request $request): View
    {
        [$from, $to, $userId, $type] = $this->filters($request);

        $logQuery = PackingLog::with(['user', 'order.items.variant.product'])
            ->whereBetween('scanned_at', [$from, $to])
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->when($type, fn ($q) => $q->whereHas(
                'order.items.variant.product',
                fn ($qq) => $qq->where('type', $type)
            ));

        // Detail scan (dipaginate). Filter type sudah di-apply di whereHas, jadi
        // hanya scan yang punya minimal 1 item dengan jenis tsb yang muncul.
        $logs = (clone $logQuery)
            ->latest('scanned_at')
            ->paginate(30)
            ->withQueryString();

        // Ringkasan per user. Kalau filter type aktif, totalnya dihitung dari
        // item yang COCOK saja (bukan dari kolom snapshot total_items log,
        // karena log tidak tahu jenis barang).
        if ($type) {
            $summary = $this->summaryByType((clone $logQuery)->get(), $type);
        } else {
            $summary = PackingLog::selectRaw('user_id, COUNT(*) as total_orders, SUM(total_items) as total_items, SUM(distinct_skus) as total_distinct')
                ->with('user:id,name')
                ->whereBetween('scanned_at', [$from, $to])
                ->when($userId, fn ($q) => $q->where('user_id', $userId))
                ->groupBy('user_id')
                ->orderByDesc('total_items')
                ->get();
        }

        $users = User::where('role', User::ROLE_PACKING)->orWhere('role', User::ROLE_ADMIN)
            ->orderBy('name')
            ->get(['id', 'name']);

        // Daftar jenis barang unik dari master Product. Dipakai untuk dropdown
        // filter; cukup ambil yang non-empty supaya tidak ada pilihan kosong.
        $types = Product::whereNotNull('type')
            ->where('type', '!=', '')
            ->distinct()
            ->orderBy('type')
            ->pluck('type');

        return view('reports.packing', [
            'summary' => $summary,
            'logs' => $logs,
            'users' => $users,
            'types' => $types,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'userId' => $userId,
            'type' => $type,
        ]);
    }

    /**
     * Export CSV mengikuti tabel Detail Scan: 1 baris per scan (bukan per
     * item). Kolom Item (Kelengkapan) berisi semua item yang ke-pack pada
     * scan tersebut, dipisahkan newline supaya saat dibuka di Excel dengan
     * Wrap Text aktif tampilan-nya sama dengan layar.
     */
    public function export(Request $request): StreamedResponse
    {
        [$from, $to, $userId, $type] = $this->filters($request);

        $logs = PackingLog::with(['user', 'order.items.variant.product'])
            ->whereBetween('scanned_at', [$from, $to])
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->when($type, fn ($q) => $q->whereHas(
                'order.items.variant.product',
                fn ($qq) => $qq->where('type', $type)
            ))
            ->orderBy('scanned_at')
            ->get();

        $filename = "laporan_packing_{$from->format('Ymd')}_{$to->format('Ymd')}.csv";

        return response()->streamDownload(function () use ($logs) {
            $out = fopen('php://output', 'w');

            // BOM agar Excel paham UTF-8 (nama produk bisa pakai karakter
            // khusus seperti em-dash atau koma).
            fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));

            fputcsv($out, [
                'Waktu',
                'User',
                'Resi',
                'Order ID',
                'Item (Kelengkapan)',
                'Qty',
            ], ';');

            foreach ($logs as $log) {
                $order = $log->order;
                $itemsText = '';
                $totalQty = (int) $log->total_items;

                if ($order) {
                    $lines = [];
                    foreach ($order->items as $item) {
                        $name = trim(($item->product_name ?? '—').' — '.($item->variant_name ?? '—'), ' —');
                        $sku = $item->sku ? " [{$item->sku}]" : '';
                        $lines[] = "{$item->quantity}× {$name}{$sku}";
                    }
                    $itemsText = implode("\n", $lines);
                }

                fputcsv($out, [
                    $log->scanned_at->format('Y-m-d H:i:s'),
                    $log->user?->name ?? '—',
                    $log->resi_number,
                    $order->tiktok_order_id ?? '—',
                    $itemsText,
                    $totalQty,
                ], ';');
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Export PDF mengikuti tampilan layar (Ringkasan + Detail Scan).
     * Implementasi pakai print-friendly Blade view yang otomatis memanggil
     * `window.print()` saat dimuat, sehingga user bisa "Save as PDF" dari
     * dialog cetak browser tanpa perlu library tambahan.
     */
    public function exportPdf(Request $request): View
    {
        [$from, $to, $userId, $type] = $this->filters($request);

        $logQuery = PackingLog::with(['user', 'order.items.variant.product'])
            ->whereBetween('scanned_at', [$from, $to])
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->when($type, fn ($q) => $q->whereHas(
                'order.items.variant.product',
                fn ($qq) => $qq->where('type', $type)
            ));

        $logs = (clone $logQuery)->orderBy('scanned_at')->get();

        if ($type) {
            $summary = $this->summaryByType((clone $logQuery)->get(), $type);
        } else {
            $summary = PackingLog::selectRaw('user_id, COUNT(*) as total_orders, SUM(total_items) as total_items, SUM(distinct_skus) as total_distinct')
                ->with('user:id,name')
                ->whereBetween('scanned_at', [$from, $to])
                ->when($userId, fn ($q) => $q->where('user_id', $userId))
                ->groupBy('user_id')
                ->orderByDesc('total_items')
                ->get();
        }

        $userName = null;
        if ($userId) {
            $userName = User::whereKey($userId)->value('name');
        }

        return view('reports.packing_pdf', [
            'summary' => $summary,
            'logs' => $logs,
            'from' => $from,
            'to' => $to,
            'type' => $type,
            'userName' => $userName,
        ]);
    }

    /**
     * Bangun ringkasan per-user dari koleksi log yang sudah diambil.
     * Menghitung HANYA item dengan product.type yang dipilih.
     *
     * @param  \Illuminate\Support\Collection<int, PackingLog>  $logs
     */
    private function summaryByType($logs, string $type)
    {
        $byUser = [];
        foreach ($logs as $log) {
            $uid = $log->user_id;
            if (! isset($byUser[$uid])) {
                $byUser[$uid] = [
                    'user' => $log->user,
                    'orders' => [],
                    'items' => 0,
                    'distinct' => [],
                ];
            }

            $matchedInThisOrder = false;
            foreach ($log->order?->items ?? [] as $item) {
                if (($item->variant?->product?->type ?? null) !== $type) {
                    continue;
                }
                $matchedInThisOrder = true;
                $byUser[$uid]['items'] += (int) $item->quantity;
                $byUser[$uid]['distinct'][$item->sku ?? 'item-'.$item->id] = true;
            }

            // Order baru di-count kalau memang ada item type yang cocok
            if ($matchedInThisOrder) {
                $byUser[$uid]['orders'][$log->order_id] = true;
            }
        }

        return collect($byUser)
            ->map(fn ($row) => (object) [
                'user' => $row['user'],
                'total_orders' => count($row['orders']),
                'total_items' => $row['items'],
                'total_distinct' => count($row['distinct']),
            ])
            ->sortByDesc('total_items')
            ->values();
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: ?int, 3: ?string}
     */
    private function filters(Request $request): array
    {
        $from = $request->filled('from')
            ? Carbon::parse($request->query('from'))->startOfDay()
            : now()->startOfMonth();

        $to = $request->filled('to')
            ? Carbon::parse($request->query('to'))->endOfDay()
            : now()->endOfDay();

        $userId = $request->filled('user_id') ? (int) $request->query('user_id') : null;
        $type = $request->filled('type') ? trim((string) $request->query('type')) : null;

        return [$from, $to, $userId, $type];
    }
}
