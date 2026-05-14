<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StockReportController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        $products = Product::with('variants')
            ->where('is_active', true)
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($sub) use ($q) {
                    $sub->where('name', 'like', "%{$q}%")
                        ->orWhere('sku', 'like', "%{$q}%")
                        ->orWhere('type', 'like', "%{$q}%");
                });
            })
            ->orderBy('name')
            ->get();

        return view('reports.stock', compact('products', 'q'));
    }

    public function export(Request $request): StreamedResponse
    {
        $products = Product::with('variants')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $filename = 'laporan-stok-' . now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($products) {
            $handle = fopen('php://output', 'w');

            // BOM untuk Excel agar UTF-8 terbaca
            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

            // Header
            fputcsv($handle, [
                'No',
                'Produk',
                'SKU Produk',
                'Tipe',
                'Variant',
                'SKU Variant',
                'Stok',
                'Min Stok',
                'Status',
                'Harga Beli',
                'Harga Reseller',
                'Harga Jual',
            ], ';');

            $no = 1;
            foreach ($products as $product) {
                foreach ($product->variants as $variant) {
                    $status = $variant->stock <= $variant->min_stock ? 'LOW STOCK' : 'OK';
                    fputcsv($handle, [
                        $no++,
                        $product->name,
                        $product->sku,
                        $product->type ?? '-',
                        $variant->name,
                        $variant->sku,
                        $variant->stock,
                        $variant->min_stock,
                        $status,
                        $product->purchase_price,
                        $product->reseller_price,
                        $product->selling_price,
                    ], ';');
                }
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
