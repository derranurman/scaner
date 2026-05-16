@extends('layouts.app')
@section('title', 'Laporan Packing')

@section('content')
    @php($header = 'Laporan Packing')
    @php($subheader = 'Lihat aktivitas packing per user, lengkap dengan detail item & kelengkapan.')

    <div class="card">
        <form method="GET" class="grid grid-cols-1 md:grid-cols-6 gap-2 items-end">
            <div>
                <label class="label">Dari</label>
                <input type="date" name="from" value="{{ $from }}" class="input">
            </div>
            <div>
                <label class="label">Sampai</label>
                <input type="date" name="to" value="{{ $to }}" class="input">
            </div>
            <div>
                <label class="label">User</label>
                <select name="user_id" class="input">
                    <option value="">Semua user</option>
                    @foreach ($users as $u)
                        <option value="{{ $u->id }}" @selected($userId == $u->id)>{{ $u->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="label">Jenis Barang</label>
                <select name="type" class="input">
                    <option value="">Semua jenis</option>
                    @foreach ($types as $t)
                        <option value="{{ $t }}" @selected($type === $t)>{{ $t }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex gap-2">
                <button class="btn-primary flex-1" type="submit">Filter</button>
                <a href="{{ route('reports.packing') }}" class="btn-secondary">Reset</a>
            </div>
            <div>
                <a href="{{ route('reports.packing.export', request()->query()) }}" class="btn-secondary w-full text-center">Export CSV</a>
            </div>
        </form>
        @if ($type)
            <p class="mt-3 text-xs text-gray-500">
                Filter aktif: jenis barang
                <span class="badge bg-indigo-100 text-indigo-700">{{ $type }}</span>.
                Ringkasan hanya menghitung item dengan jenis ini.
            </p>
        @endif
    </div>

    <div class="card mt-6">
        <h2 class="font-semibold mb-3">Ringkasan per User</h2>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-xs uppercase text-gray-500 border-b">
                    <tr>
                        <th class="py-2">User</th>
                        <th class="py-2 text-right">Total Pesanan</th>
                        <th class="py-2 text-right">Total Item (pcs)</th>
                        <th class="py-2 text-right">Total SKU</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse ($summary as $row)
                        <tr>
                            <td class="py-2 font-medium">{{ $row->user?->name ?? '—' }}</td>
                            <td class="py-2 text-right">{{ number_format($row->total_orders) }}</td>
                            <td class="py-2 text-right font-semibold">{{ number_format($row->total_items) }}</td>
                            <td class="py-2 text-right">{{ number_format($row->total_distinct) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-6 text-center text-gray-500">Belum ada aktivitas packing pada rentang ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card mt-6">
        <h2 class="font-semibold mb-3">Detail Scan</h2>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-xs uppercase text-gray-500 border-b">
                    <tr>
                        <th class="py-2">Waktu</th>
                        <th class="py-2">User</th>
                        <th class="py-2">Resi</th>
                        <th class="py-2">Item (Kelengkapan)</th>
                        <th class="py-2 text-right">Qty</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse ($logs as $log)
                        <tr class="align-top">
                            <td class="py-3 text-xs">{{ $log->scanned_at->format('d M Y H:i') }}</td>
                            <td class="py-3 font-medium">{{ $log->user?->name }}</td>
                            <td class="py-3 font-mono text-xs">
                                <a class="text-indigo-600 hover:underline" href="{{ route('orders.show', $log->order_id) }}">
                                    {{ $log->resi_number }}
                                </a>
                            </td>
                            <td class="py-3">
                                <ul class="space-y-0.5">
                                    @foreach ($log->order?->items ?? [] as $item)
                                        <li class="flex items-center gap-2 text-xs">
                                            <span class="badge bg-gray-100 text-gray-700">{{ $item->quantity }}×</span>
                                            <span>{{ $item->product_name }} — {{ $item->variant_name ?? '—' }}</span>
                                            <span class="text-gray-400 font-mono">{{ $item->sku }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            </td>
                            <td class="py-3 text-right font-semibold">{{ $log->total_items }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-6 text-center text-gray-500">Tidak ada data.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $logs->links() }}</div>
    </div>
@endsection
