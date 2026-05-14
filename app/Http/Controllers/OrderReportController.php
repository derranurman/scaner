<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\OrderMetricsService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OrderReportController extends Controller
{
    public function __construct(private OrderMetricsService $metrics)
    {
    }

    public function index(Request $request): View
    {
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');
        $status = $request->query('status');

        $orders = Order::with(['items.variant.product', 'platformDeduction'])
            ->when($startDate, fn ($q) => $q->whereDate('order_date', '>=', $startDate))
            ->when($endDate, fn ($q) => $q->whereDate('order_date', '<=', $endDate))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->latest('id')
            ->get();

        // Compute metrics for summary
        $totalJual = 0;
        $totalModal = 0;
        $totalProfit = 0;
        $metrics = [];

        foreach ($orders as $order) {
            $m = $this->metrics->compute($order);
            $metrics[$order->id] = $m;
            $totalJual += $m['total_jual'];
            $totalModal += $m['total_modal'];
            $totalProfit += $m['profit_kotor'];
        }

        $statusCounts = [
            'pending' => $orders->where('status', 'pending')->count(),
            'packed' => $orders->where('status', 'packed')->count(),
            'selesai' => $orders->where('status', 'selesai')->count(),
            'return' => $orders->where('status', 'return')->count(),
            'cancelled' => $orders->where('status', 'cancelled')->count(),
        ];

        return view('reports.orders', compact(
            'orders',
            'metrics',
            'totalJual',
            'totalModal',
            'totalProfit',
            'statusCounts',
            'startDate',
            'endDate',
            'status',
        ));
    }
}
