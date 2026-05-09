<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Variant;
use App\Services\StockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class VariantController extends Controller
{
    public function __construct(private StockService $stock)
    {
    }

    public function store(Request $request, Product $product): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'sku' => ['required', 'string', 'max:100', 'unique:variants,sku'],
            'stock' => ['required', 'integer', 'min:0'],
            'min_stock' => ['required', 'integer', 'min:0'],
        ]);

        $product->variants()->create($data);

        return back()->with('success', 'Varian ditambahkan.');
    }

    public function update(Request $request, Variant $variant): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'sku' => ['required', 'string', 'max:100', 'unique:variants,sku,'.$variant->id],
            'min_stock' => ['required', 'integer', 'min:0'],
        ]);

        $variant->update($data);

        return back()->with('success', 'Varian diperbarui.');
    }

    public function destroy(Variant $variant): RedirectResponse
    {
        $variant->delete();

        return back()->with('success', 'Varian dihapus.');
    }

    public function adjust(Request $request, Variant $variant): RedirectResponse
    {
        $data = $request->validate([
            'qty' => ['required', 'integer', 'not_in:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $this->stock->adjust(
            $variant,
            (int) $data['qty'],
            StockMovement::TYPE_ADJUSTMENT,
            $request->user()->id,
            null,
            $data['note'] ?? 'Penyesuaian manual',
        );

        return back()->with('success', 'Stok disesuaikan.');
    }
}
