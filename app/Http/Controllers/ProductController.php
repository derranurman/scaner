<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        $products = Product::with('variants')
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($sub) use ($q) {
                    $sub->where('name', 'like', "%{$q}%")
                        ->orWhere('sku', 'like', "%{$q}%")
                        ->orWhereHas('variants', fn ($v) => $v->where('sku', 'like', "%{$q}%")->orWhere('name', 'like', "%{$q}%"));
                });
            })
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('products.index', compact('products', 'q'));
    }

    public function create(): View
    {
        return view('products.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'sku' => ['required', 'string', 'max:100', 'unique:products,sku'],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data['is_active'] = $request->boolean('is_active', true);

        $product = Product::create($data);

        return redirect()->route('products.edit', $product)->with('success', 'Produk dibuat. Tambahkan varian di bawah.');
    }

    public function edit(Product $product): View
    {
        $product->load('variants');

        return view('products.edit', compact('product'));
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'sku' => ['required', 'string', 'max:100', 'unique:products,sku,'.$product->id],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data['is_active'] = $request->boolean('is_active', false);

        $product->update($data);

        return back()->with('success', 'Produk diperbarui.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        $product->delete();

        return redirect()->route('products.index')->with('success', 'Produk dihapus.');
    }

    public function show(Product $product): RedirectResponse
    {
        return redirect()->route('products.edit', $product);
    }
}
