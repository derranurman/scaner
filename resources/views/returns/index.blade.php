@extends('layouts.app')
@section('title', 'Kelola Return')

@section('content')
    <?php $header = 'Kelola Return'; ?>
    <?php $subheader = 'Daftar pesanan yang di-return. Bisa tandai return baru atau kembalikan status.'; ?>

    <div class="card">
        {{-- Form tandai return --}}
        <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg">
            <h3 class="text-sm font-semibold text-red-800 mb-2">Tandai Return Baru</h3>
            <form method="POST" action="{{ route('returns.mark') }}" class="grid grid-cols-1 md:grid-cols-4 gap-2">
                @csrf
                <input type="text" name="resi_number" required placeholder="Nomor Resi" class="input">
                <input type="text" name="reason" placeholder="Alasan return (opsional)" class="input md:col-span-2">
                <button type="submit" class="btn-primary bg-red-600 hover:bg-red-700">Tandai Return</button>
            </form>
            @if ($errors->any())
                <p class="text-xs text-red-600 mt-2">{{ $errors->first() }}</p>
            @endif
        </div>

        {{-- Search --}}
        <form method="GET" class="flex gap-2 mb-4">
            <input name="q" value="{{ $q }}" placeholder="Cari resi / pembeli…" class="input w-64">
            <button class="btn-primary" type="submit">Cari</button>
            @if ($q)
                <a href="{{ route('returns.index') }}" class="btn-secondary">Reset</a>
            @endif
        </form>

        {{-- Table --}}
        <div class="overflow-x-auto">
            <table class="text-xs whitespace-nowrap border-collapse w-full">
                <thead class="text-left uppercase text-gray-500 border-b bg-gray-50">
                    <tr>
                        <th class="px-2 py-2">No</th>
                        <th class="px-2 py-2">Resi</th>
                        <th class="px-2 py-2">Tanggal</th>
                        <th class="px-2 py-2">Pembeli</th>
                        <th class="px-2 py-2">SKU</th>
                        <th class="px-2 py-2 text-right">Total Jual</th>
                        <th class="px-2 py-2 text-right">Total Modal</th>
                        <th class="px-2 py-2">Catatan</th>
                        <th class="px-2 py-2 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse ($orders as $i => $order)
                        @php
                            $m = $metrics[$order->id];
                            $skus = $order->items->pluck('sku')->filter()->unique()->implode(', ');
                        @endphp
                        <tr class="hover:bg-gray-50">
                            <td class="px-2 py-2">{{ ($orders->currentPage() - 1) * $orders->perPage() + $i + 1 }}</td>
                            <td class="px-2 py-2 font-mono">{{ $order->resi_number }}</td>
                            <td class="px-2 py-2">{{ $order->order_date?->format('d/m/Y') ?? '-' }}</td>
                            <td class="px-2 py-2">{{ $order->buyer_name ?? '-' }}</td>
                            <td class="px-2 py-2 font-mono">{{ $skus ?: '-' }}</td>
                            <td class="px-2 py-2 text-right font-mono">Rp {{ number_format($m['total_jual'], 0, ',', '.') }}</td>
                            <td class="px-2 py-2 text-right font-mono">Rp {{ number_format($m['total_modal'], 0, ',', '.') }}</td>
                            <td class="px-2 py-2 max-w-xs truncate" title="{{ $order->notes }}">{{ \Illuminate\Support\Str::limit($order->notes, 50) }}</td>
                            <td class="px-2 py-2 text-right">
                                <form method="POST" action="{{ route('returns.undo', $order) }}" class="inline"
                                      onsubmit="return confirm('Kembalikan pesanan ini ke status Pending?');">
                                    @csrf
                                    <button type="submit" class="text-indigo-600 hover:underline text-xs">Undo Return</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="py-6 text-center text-gray-500">
                                Tidak ada pesanan return. Semua aman!
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $orders->links() }}</div>
    </div>
@endsection
