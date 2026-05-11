@extends('layouts.app')
@section('title', 'Laporan Produk')

@section('content')
    @php($header = 'Laporan Produk')
    @php($subheader = 'Pergerakan stok (barang masuk, keluar, penyesuaian) per produk.')

    <div class="card">
        <form method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-2 items-end">
            <div>
                <label class="label">Dari</label>
                <input type="date" name="from" value="{{ $from }}" class="input">
            </div>
            <div>
                <label class="label">Sampai</label>
                <input type="date" name="to" value="{{ $to }}" class="input">
            </div>
            <div>
                <label class="label">Produk</label>
                <select name="product_id" class="input">
                    <option value="">Semua produk</option>
                    @foreach ($products as $p)
                        <option value="{{ $p->id }}" @selected($productId == $p->id)>{{ $p->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="label">Tipe</label>
                <select name="type" class="input">
                    <option value="">Semua tipe</option>
                    <option value="in"         @selected($type === 'in')>Barang Masuk</option>
                    <option value="out"        @selected($type === 'out')>Barang Keluar</option>
                    <option value="adjustment" @selected($type === 'adjustment')>Penyesuaian</option>
                </select>
            </div>
            <div class="flex gap-2">
                <button class="btn-primary flex-1" type="submit">Filter</button>
                <a href="{{ route('reports.products') }}" class="btn-secondary">Reset</a>
            </div>
        </form>
    </div>

    <div class="card mt-6">
        <h2 class="font-semibold mb-3">Ringkasan per Produk</h2>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-xs uppercase text-gray-500 border-b">
                    <tr>
                        <th class="py-2">Produk</th>
                        <th class="py-2 text-right">Masuk (pcs)</th>
                        <th class="py-2 text-right">Keluar (pcs)</th>
                        <th class="py-2 text-right">Penyesuaian (pcs)</th>
                        <th class="py-2 text-right">Selisih Bersih</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse ($summary as $pid => $row)
                        @php
                            $net = $row['in'] + $row['adjustment'] - abs($row['out']);
                            // out biasanya negatif (karena adjust -qty). Amankan dengan abs.
                            $outAbs = abs($row['out']);
                        @endphp
                        <tr>
                            <td class="py-3 font-medium">{{ $productsMap[$pid]->name ?? '—' }}</td>
                            <td class="py-3 text-right font-semibold text-green-600">+{{ number_format((int) $row['in']) }}</td>
                            <td class="py-3 text-right font-semibold text-red-600">-{{ number_format($outAbs) }}</td>
                            <td class="py-3 text-right text-gray-700">{{ $row['adjustment'] >= 0 ? '+' : '' }}{{ number_format((int) $row['adjustment']) }}</td>
                            <td class="py-3 text-right font-semibold {{ $net >= 0 ? 'text-green-600' : 'text-red-600' }}">
                                {{ $net >= 0 ? '+' : '' }}{{ number_format($net) }}
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-6 text-center text-gray-500">Tidak ada pergerakan stok pada rentang ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card mt-6">
        <h2 class="font-semibold mb-3">Riwayat Pergerakan</h2>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-xs uppercase text-gray-500 border-b">
                    <tr>
                        <th class="py-2">Waktu</th>
                        <th class="py-2">Tipe</th>
                        <th class="py-2">Produk — Varian</th>
                        <th class="py-2">User</th>
                        <th class="py-2">Referensi</th>
                        <th class="py-2 text-right">Qty</th>
                        <th class="py-2 text-right">Stok Setelah</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse ($movements as $m)
                        <tr>
                            <td class="py-2 text-xs whitespace-nowrap">{{ $m->created_at->format('d M Y H:i') }}</td>
                            <td class="py-2">
                                @if ($m->type === 'in')
                                    <span class="badge bg-green-100 text-green-700">Masuk</span>
                                @elseif ($m->type === 'out')
                                    <span class="badge bg-red-100 text-red-700">Keluar</span>
                                @else
                                    <span class="badge bg-gray-100 text-gray-600">Penyesuaian</span>
                                @endif
                            </td>
                            <td class="py-2">
                                <div class="font-medium">{{ $m->variant?->product?->name ?? '—' }}</div>
                                <div class="text-xs text-gray-500">{{ $m->variant?->name }} <span class="font-mono">{{ $m->variant?->sku }}</span></div>
                            </td>
                            <td class="py-2 text-xs">{{ $m->user?->name ?? '—' }}</td>
                            <td class="py-2 text-xs text-gray-600">{{ $m->reference ?? '—' }}</td>
                            <td class="py-2 text-right font-semibold {{ $m->qty >= 0 ? 'text-green-600' : 'text-red-600' }}">
                                {{ $m->qty >= 0 ? '+' : '' }}{{ number_format((int) $m->qty) }}
                            </td>
                            <td class="py-2 text-right">{{ number_format((int) $m->stock_after) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-6 text-center text-gray-500">Tidak ada data.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $movements->links() }}</div>
    </div>
@endsection
