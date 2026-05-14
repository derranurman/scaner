<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\OrderMetricsService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReturnReportController extends Controller
{
    public function __construct(private OrderMetricsService $metrics)
    {
    }

    public function index(Request $request): View
    {
        $month = (int) $request->query('month', now()->month);
        $year = (int) $request->query('year', now()->year);

        $orders = Order::with(['items.variant.product', 'platformDeduction'])
            ->where('status', Order::STATUS_RETURN)
            ->whereYear('updated_at', $year)
            ->whereMonth('updated_at', $month)
            ->latest('updated_at')
            ->get();

        // Metrics for current month returns
        $totalKerugian = 0;
        $totalJualHilang = 0;
        $metrics = [];

        foreach ($orders as $order) {
            $m = $this->metrics->compute($order);
            $metrics[$order->id] = $m;
            $totalKerugian += $m['total_modal'];
            $totalJualHilang += $m['total_jual'];
        }

        $returnCount = $orders->count();

        // Previous month count for comparison
        $prevDate = Carbon::create($year, $month, 1)->subMonth();
        $prevMonthCount = Order::where('status', Order::STATUS_RETURN)
            ->whereYear('updated_at', $prevDate->year)
            ->whereMonth('updated_at', $prevDate->month)
            ->count();

        return view('reports.returns', compact(
            'orders',
            'metrics',
            'returnCount',
            'totalKerugian',
            'totalJualHilang',
            'prevMonthCount',
            'month',
            'year',
        ));
    }
}
