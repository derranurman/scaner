@extends('layouts.app')
@section('title', 'Pesanan')

@section('content')
    @php($header = 'Pesanan')

    <div class="card">
        <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-2 mb-4">
            <input name="q" value="{{ $q }}" placeholder="Cari resi / order id / nama…" class="input">
            <select name="status" class="input">
                <option value="">Semua status</option>
                <option value="pending"   @selected($status === 'pending')>Pending</option>
                <option value="packed"    @selected($status === 'packed')>Packed</option>
                <option value="cancelled" @selected($status === 'cancelled')>Cancelled</option>
            </select>
            <input type="date" name="date" value="{{ $date }}" class="input">
            <div class="flex gap-2">
                <button class="btn-primary flex-1" type="submit">Filter</button>
                <a href="{{ route('orders.index') }}" class="btn-secondary">Reset</a>
            </div>
        </form>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-xs uppercase text-gray-500 border-b">
                    <tr>
                        <th class="py-2">Resi</th>
                        <th class="py-2">Order ID</th>
                        <th class="py-2">Pembeli</th>
                        <th class="py-2">Items</th>
                        <th class="py-2">Status</th>
                        <th class="py-2">Packed oleh</th>
                        <th class="py-2 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse ($orders as $order)
                        <tr>
                            <td class="py-3 font-mono text-xs">{{ $order->resi_number }}</td>
                            <td class="py-3 text-xs text-gray-500">{{ $order->tiktok_order_id ?? '—' }}</td>
                            <td class="py-3">{{ $order->buyer_name ?? '—' }}</td>
                            <td class="py-3">{{ $order->items_count }} jenis / {{ $order->items_sum_quantity ?? 0 }} pcs</td>
                            <td class="py-3">
                                @if ($order->status === 'pending')
                                    <span class="badge bg-amber-100 text-amber-700">Pending</span>
                                @elseif ($order->status === 'packed')
                                    <span class="badge bg-green-100 text-green-700">Packed</span>
                                @else
                                    <span class="badge bg-gray-100 text-gray-600">Cancelled</span>
                                @endif
                            </td>
                            <td class="py-3 text-xs">
                                {{ $order->packedBy?->name ?? '—' }}
                                @if ($order->packed_at)
                                    <div class="text-gray-500">{{ $order->packed_at->format('d M H:i') }}</div>
                                @endif
                            </td>
                            <td class="py-3 text-right">
                                <a href="{{ route('orders.show', $order) }}" class="text-indigo-600 hover:underline">Detail</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-6 text-center text-gray-500">Belum ada pesanan. <a href="{{ route('orders.import.show') }}" class="text-indigo-600 hover:underline">Import CSV →</a></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $orders->links() }}</div>
    </div>
@endsection
