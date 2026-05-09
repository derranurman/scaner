@extends('layouts.app')
@section('title', 'Produk')

@section('content')
    @php($header = 'Produk')

    <div class="card">
        <div class="flex flex-col md:flex-row md:items-center gap-3 md:justify-between mb-4">
            <form method="GET" class="flex gap-2 flex-1">
                <input name="q" value="{{ $q }}" placeholder="Cari nama / SKU / varian…" class="input md:max-w-xs">
                <button class="btn-secondary" type="submit">Cari</button>
            </form>
            <a href="{{ route('products.create') }}" class="btn-primary">+ Produk Baru</a>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-xs uppercase text-gray-500 border-b">
                    <tr>
                        <th class="py-2">Produk</th>
                        <th class="py-2">SKU</th>
                        <th class="py-2">Varian</th>
                        <th class="py-2">Total Stok</th>
                        <th class="py-2">Status</th>
                        <th class="py-2 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse ($products as $product)
                        <tr>
                            <td class="py-3 font-medium">{{ $product->name }}</td>
                            <td class="py-3 font-mono text-xs">{{ $product->sku }}</td>
                            <td class="py-3">
                                @foreach ($product->variants as $v)
                                    <span class="badge bg-gray-100 text-gray-700 mr-1 mb-1">{{ $v->name }} ({{ $v->stock }})</span>
                                @endforeach
                                @if ($product->variants->isEmpty())
                                    <span class="text-xs text-gray-400 italic">Belum ada</span>
                                @endif
                            </td>
                            <td class="py-3">{{ $product->totalStock() }}</td>
                            <td class="py-3">
                                @if ($product->is_active)
                                    <span class="badge bg-green-100 text-green-700">Aktif</span>
                                @else
                                    <span class="badge bg-gray-100 text-gray-600">Nonaktif</span>
                                @endif
                            </td>
                            <td class="py-3 text-right">
                                <a href="{{ route('products.edit', $product) }}" class="text-indigo-600 hover:underline">Edit</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-6 text-center text-gray-500">Belum ada produk.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $products->links() }}</div>
    </div>
@endsection
