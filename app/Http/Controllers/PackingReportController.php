<?php

namespace App\Http\Controllers;

use App\Models\PackingLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\View\View;

class PackingReportController extends Controller
{
    public function index(Request $request): View
    {
        [$from, $to, $userId] = $this->filters($request);

        // Ringkasan per user
        $summary = PackingLog::selectRaw('user_id, COUNT(*) as total_orders, SUM(total_items) as total_items, SUM(distinct_skus) as total_distinct')
            ->with('user:id,name')
            ->whereBetween('scanned_at', [$from, $to])
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->groupBy('user_id')
            ->orderByDesc('total_items')
            ->get();

        // Detail scan (dipaginate)
        $logs = PackingLog::with(['user', 'order.items'])
            ->whereBetween('scanned_at', [$from, $to])
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->latest('scanned_at')
            ->paginate(30)
            ->withQueryString();

        $users = User::where('role', User::ROLE_PACKING)->orWhere('role', User::ROLE_ADMIN)
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('reports.packing', [
            'summary' => $summary,
            'logs' => $logs,
            'users' => $users,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'userId' => $userId,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        [$from, $to, $userId] = $this->filters($request);

        $logs = PackingLog::with(['user', 'order.items'])
            ->whereBetween('scanned_at', [$from, $to])
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->orderBy('scanned_at')
            ->get();

        $filename = "laporan_packing_{$from->format('Ymd')}_{$to->format('Ymd')}.csv";

        return response()->streamDownload(function () use ($logs) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Tanggal', 'User', 'Resi', 'Order ID TT', 'Item (Produk - Varian)', 'SKU', 'Qty']);

            foreach ($logs as $log) {
                $order = $log->order;
                if (! $order) {
                    continue;
                }
                foreach ($order->items as $item) {
                    fputcsv($out, [
                        $log->scanned_at->format('Y-m-d H:i:s'),
                        $log->user?->name,
                        $log->resi_number,
                        $order->tiktok_order_id,
                        trim($item->product_name.' - '.($item->variant_name ?? '')),
                        $item->sku,
                        $item->quantity,
                    ]);
                }
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: ?int}
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

        return [$from, $to, $userId];
    }
}
