<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Variant;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class ProductReportController extends Controller
{
    public function index(Request $request): View
    {
        [$from, $to, $productId, $type] = $this->filters($request);

        // Ringkasan per produk: jumlah barang masuk & keluar dalam rentang
        $summaryRows = StockMovement::query()
            ->selectRaw('variants.product_id, stock_movements.type, SUM(stock_movements.qty) as total_qty')
            ->join('variants', 'variants.id', '=', 'stock_movements.variant_id')
            ->whereBetween('stock_movements.created_at', [$from, $to])
            ->when($productId, fn ($q) => $q->where('variants.product_id', $productId))
            ->groupBy('variants.product_id', 'stock_movements.type')
            ->get();

        $summary = [];
        foreach ($summaryRows as $row) {
            $pid = $row->product_id;
            if (! isset($summary[$pid])) {
                $summary[$pid] = ['in' => 0, 'out' => 0, 'adjustment' => 0];
            }
            $summary[$pid][$row->type] = (int) $row->total_qty;
        }

        $productIds = array_keys($summary);
        $productsMap = Product::whereIn('id', $productIds)->get()->keyBy('id');

        // Riwayat detail (paginate)
        $movements = StockMovement::query()
            ->with(['variant.product', 'user'])
            ->whereBetween('stock_movements.created_at', [$from, $to])
            ->when($productId, fn ($q) => $q->whereHas('variant', fn ($sub) => $sub->where('product_id', $productId)))
            ->when($type, fn ($q) => $q->where('type', $type))
            ->orderByDesc('created_at')
            ->paginate(30)
            ->withQueryString();

        $products = Product::orderBy('name')->get(['id', 'name']);

        return view('reports.products', [
            'summary' => $summary,
            'productsMap' => $productsMap,
            'movements' => $movements,
            'products' => $products,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'productId' => $productId,
            'type' => $type,
        ]);
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

        $productId = $request->filled('product_id') ? (int) $request->query('product_id') : null;
        $type = $request->filled('type') ? (string) $request->query('type') : null;

        return [$from, $to, $productId, $type];
    }
}
