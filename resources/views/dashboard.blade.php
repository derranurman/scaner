@extends('layouts.app')
@section('title', 'Dashboard')

@section('content')
    @php($header = 'Dashboard')

    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
        @foreach ([
            ['label' => 'Produk', 'value' => $stats['total_products'], 'color' => 'indigo'],
            ['label' => 'Varian', 'value' => $stats['total_variants'], 'color' => 'sky'],
            ['label' => 'Total Stok', 'value' => $stats['total_stock'], 'color' => 'emerald'],
            ['label' => 'Pesanan Pending', 'value' => $stats['pending_orders'], 'color' => 'amber'],
            ['label' => 'Packed Hari Ini', 'value' => $stats['packed_today'], 'color' => 'teal'],
            ['label' => 'Stok Menipis', 'value' => $stats['low_stock'], 'color' => 'red'],
        ] as $stat)
            <div class="card">
                <div class="text-xs font-medium text-gray-500 uppercase">{{ $stat['label'] }}</div>
                <div class="mt-2 text-2xl font-bold text-{{ $stat['color'] }}-600">{{ number_format($stat['value']) }}</div>
            </div>
        @endforeach
    </div>

    <div class="mt-6 grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="card">
            <h2 class="font-semibold text-gray-900 mb-3">Scan Terbaru</h2>
            @if ($recent->isEmpty())
                <p class="text-sm text-gray-500">Belum ada aktivitas scan.</p>
            @else
                <div class="divide-y">
                    @foreach ($recent as $log)
                        <div class="py-2 flex items-center justify-between text-sm">
                            <div>
                                <div class="font-medium">{{ $log->resi_number }}</div>
                                <div class="text-xs text-gray-500">{{ $log->user?->name }} · {{ $log->scanned_at->format('d M Y H:i') }}</div>
                            </div>
                            <span class="badge bg-green-100 text-green-700">{{ $log->total_items }} pcs</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="card">
            <h2 class="font-semibold text-gray-900 mb-3">Stok Menipis</h2>
            @if ($lowStock->isEmpty())
                <p class="text-sm text-gray-500">Semua stok aman.</p>
            @else
                <div class="divide-y">
                    @foreach ($lowStock as $v)
                        <div class="py-2 flex items-center justify-between text-sm">
                            <div>
                                <div class="font-medium">{{ $v->product?->name }} — {{ $v->name }}</div>
                                <div class="text-xs text-gray-500 font-mono">{{ $v->sku }}</div>
                            </div>
                            <span class="badge {{ $v->stock <= 0 ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700' }}">
                                {{ $v->stock }} / min {{ $v->min_stock }}
                            </span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
@endsection
