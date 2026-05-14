@extends('layouts.app')
@section('title', 'Laporan Return')

@section('content')
    <?php $header = 'Laporan Return'; ?>
    <?php $subheader = 'Rekap pesanan return berdasarkan periode. Bulan ini vs bulan kemarin.'; ?>

    <div class="card">
        <form method="GET" class="flex gap-2 mb-4 flex-wrap">
            <select name="month" class="input w-40">
                @for ($m = 1; $m <= 12; $m++)
                    <option value="{{ $m }}" {{ $month == $m ? 'selected' : '' }}>
                        {{ \Carbon\Carbon::create()->month($m)->translatedFormat('F') }}
                    </option>
                @endfor
            </select>
            <select name="year" class="input w-28">
                @for ($y = now()->year; $y >= now()->year - 2; $y--)
                    <option value="{{ $y }}" {{ $year == $y ? 'selected' : '' }}>{{ $y }}</option>
                @endfor
            </select>
            <button class="btn-primary" type="submit">Filter</button>
            <a href="{{ route('reports.returns') }}" class="btn-secondary">Bulan Ini</a>
        </form>

        {{-- Summary --}}
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
            <div class="bg-red-50 rounded-lg p-3 text-center">
                <div class="text-xs text-red-600 font-medium">Jumlah Return</div>
                <div class="text-lg font-bold text-red-800">{{ $returnCount }}</div>
            </div>
            <div class="bg-amber-50 rounded-lg p-3 text-center">
                <div class="text-xs text-amber-600 font-medium">Kerugian Modal</div>
                <div class="text-lg font-bold text-amber-800">Rp {{ number_format($totalKerugian, 0, ',', '.') }}</div>
            </div>
            <div class="bg-blue-50 rounded-lg p-3 text-center">
                <div class="text-xs text-blue-600 font-medium">Total Jual Hilang</div>
                <div class="text-lg font-bold text-blue-800">Rp {{ number_format($totalJualHilang, 0, ',', '.') }}</div>
            </div>
            <div class="bg-gray-50 rounded-lg p-3 text-center">
                <div class="text-xs text-gray-600 font-medium">Bulan Sebelum</div>
                <div class="text-lg font-bold text-gray-800">{{ $prevMonthCount }} return</div>
            </div>
        </div>

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
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse ($orders as $i => $order)
                        @php
                            $m = $metrics[$order->id];
                            $skus = $order->items->pluck('sku')->filter()->unique()->implode(', ');
                        @endphp
                        <tr class="hover:bg-gray-50">
                            <td class="px-2 py-2">{{ $i + 1 }}</td>
                            <td class="px-2 py-2 font-mono">{{ $order->resi_number }}</td>
                            <td class="px-2 py-2">{{ $order->order_date?->format('d/m/Y') ?? $order->updated_at?->format('d/m/Y') }}</td>
                            <td class="px-2 py-2">{{ $order->buyer_name ?? '-' }}</td>
                            <td class="px-2 py-2 font-mono">{{ $skus ?: '-' }}</td>
                            <td class="px-2 py-2 text-right font-mono text-red-600">Rp {{ number_format($m['total_jual'], 0, ',', '.') }}</td>
                            <td class="px-2 py-2 text-right font-mono text-red-600">Rp {{ number_format($m['total_modal'], 0, ',', '.') }}</td>
                            <td class="px-2 py-2 max-w-xs truncate" title="{{ $order->notes }}">{{ \Illuminate\Support\Str::limit($order->notes, 40) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="py-6 text-center text-gray-500">Tidak ada pesanan return di bulan ini.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
