@extends('layouts.app')
@section('title', 'Pesanan')

@section('content')
    <?php $header = 'Pesanan'; ?>

    <div class="card">
        <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
            <p class="text-xs text-gray-500">
                Tabel bergerak horizontal. Host Live &amp; Platform bisa diedit langsung
                (perubahan platform akan otomatis menghitung ulang ADM, Ongkir, Pajak, dll).
            </p>
            <div class="flex gap-2">
                <a href="{{ route('orders.create') }}" class="btn-primary">+ Tambah Pesanan</a>
                <a href="{{ route('orders.export', request()->query()) }}" class="btn-secondary">⬇ Export Excel</a>
            </div>
        </div>

        <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-2 mb-4">
            <input name="q" value="{{ $q }}" placeholder="Cari resi / order id / pembeli / host…" class="input">
            <select name="status" class="input">
                <option value="">Semua status</option>
                <option value="pending"   <?php if ($status === 'pending') echo 'selected'; ?>>Pending</option>
                <option value="packed"    <?php if ($status === 'packed') echo 'selected'; ?>>Packed</option>
                <option value="cancelled" <?php if ($status === 'cancelled') echo 'selected'; ?>>Cancelled</option>
                <option value="return"    <?php if ($status === 'return') echo 'selected'; ?>>Return</option>
                <option value="selesai"   <?php if ($status === 'selesai') echo 'selected'; ?>>Selesai</option>
                <option value="selesai_bulan_kemarin" <?php if ($status === 'selesai_bulan_kemarin') echo 'selected'; ?>>Selesai Bulan Kemarin</option>
            </select>
            <input type="date" name="date" value="{{ $date }}" class="input">
            <div class="flex gap-2">
                <button class="btn-primary flex-1" type="submit">Filter</button>
                <a href="{{ route('orders.index') }}" class="btn-secondary">Reset</a>
            </div>
        </form>

        <div class="overflow-x-auto">
            <table class="text-xs whitespace-nowrap border-collapse">
                <thead class="text-left uppercase text-gray-500 border-b bg-gray-50">
                    <tr>
                        <th class="px-2 py-2">No</th>
                        <th class="px-2 py-2">Resi</th>
                        <th class="px-2 py-2">Host Live</th>
                        <th class="px-2 py-2">Platform</th>
                        <th class="px-2 py-2">Pengirim</th>
                        <th class="px-2 py-2">Pembeli</th>
                        <th class="px-2 py-2">No. HP</th>
                        <th class="px-2 py-2">SKU</th>
                        <th class="px-2 py-2 text-right">Harga Jual</th>
                        <th class="px-2 py-2 text-right">Total Jual</th>
                        <th class="px-2 py-2 text-right">Total Modal</th>
                        <th class="px-2 py-2 text-right">Total Reseller</th>
                        <th class="px-2 py-2 text-right">Ongkir Cargo</th>
                        <th class="px-2 py-2 text-right">Yield</th>
                        <th class="px-2 py-2 text-right">Plastik/Dus</th>
                        <th class="px-2 py-2 text-right">Operasional</th>
                        <th class="px-2 py-2 text-right">ADM (%)</th>
                        <th class="px-2 py-2 text-right">Ongkir Free (%)</th>
                        <th class="px-2 py-2 text-right">Bulat Max 650Rb</th>
                        <th class="px-2 py-2 text-right">Biaya Layanan (Rp)</th>
                        <th class="px-2 py-2 text-right">Biaya Logistik (Rp)</th>
                        <th class="px-2 py-2 text-right">Pajak (%)</th>
                        <th class="px-2 py-2 text-right">Profit Kotor</th>
                        <th class="px-2 py-2 text-right">% Profit Kotor</th>
                        <th class="px-2 py-2 text-right">Margin Bisnis</th>
                        <th class="px-2 py-2 text-right">% Margin Bisnis</th>
                        <th class="px-2 py-2 text-right">Margin Live</th>
                        <th class="px-2 py-2 text-right">% Margin Live</th>
                        <th class="px-2 py-2 text-right">Bersih Margin Live</th>
                        <th class="px-2 py-2 text-right">TOTAL POTONGAN APLIKASI</th>
                        <th class="px-2 py-2">Status</th>
                        <th class="px-2 py-2 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php if ($orders->isEmpty()): ?>
                        <tr>
                            <td colspan="32" class="py-6 text-center text-gray-500">
                                Belum ada pesanan.
                                <a href="{{ route('orders.import.pdf.show') }}" class="text-indigo-600 hover:underline">Import PDF &rarr;</a>
                                <span class="text-gray-400">·</span>
                                <a href="{{ route('orders.create') }}" class="text-indigo-600 hover:underline">Tambah manual &rarr;</a>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($orders as $iOrder => $order): ?>
                            <?php
                                $m = $metrics[$order->id];

                                // Ringkas: gabung SKU semua item, harga jual ambil item pertama.
                                $skuList = [];
                                $firstSellingPrice = null;
                                foreach ($order->items as $it) {
                                    if ($it->sku) {
                                        $skuList[] = $it->sku;
                                    }
                                    if ($firstSellingPrice === null) {
                                        $firstSellingPrice = (float) ($it->variant?->product?->selling_price ?? 0);
                                    }
                                }
                                $skuDisplay = implode(', ', array_unique($skuList));
                                $no = $startNo + $iOrder;

                                $fmt = fn ($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
                                $pct = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.') . '%';

                                $profitClass = $m['profit_kotor'] >= 0 ? 'text-green-600' : 'text-red-600';
                                $marginBisnisClass = $m['margin_bisnis'] >= 0 ? 'text-green-600' : 'text-red-600';
                                $marginLiveClass = $m['margin_live'] >= 0 ? 'text-green-600' : 'text-red-600';
                            ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-2 py-2">{{ $no }}</td>

                                <td class="px-2 py-2 font-mono">
                                    <a href="{{ route('orders.show', $order) }}" class="text-indigo-600 hover:underline">{{ $order->resi_number }}</a>
                                    <div class="text-gray-400 normal-case">{{ $order->tiktok_order_id }}</div>
                                </td>

                                {{-- Host Live & Platform: inline form --}}
                                <td class="px-2 py-2">
                                    <form method="POST" action="{{ route('orders.update_meta', $order) }}" class="inline-flex items-center gap-1">
                                        @csrf
                                        <input type="text" name="host_live" value="{{ $order->host_live }}"
                                               placeholder="—" class="input text-xs py-1 w-28">
                                        <input type="hidden" name="platform_deduction_id" value="{{ $order->platform_deduction_id }}">
                                        <button type="submit" class="text-indigo-600 hover:underline text-xs">OK</button>
                                    </form>
                                </td>

                                <td class="px-2 py-2">
                                    <form method="POST" action="{{ route('orders.update_meta', $order) }}" class="inline-flex items-center gap-1">
                                        @csrf
                                        <input type="hidden" name="host_live" value="{{ $order->host_live }}">
                                        <select name="platform_deduction_id" onchange="this.form.submit()" class="input text-xs py-1 w-36">
                                            <option value="">— pilih —</option>
                                            <?php foreach ($platforms as $p): ?>
                                                <option value="{{ $p->id }}" <?php if ((int) $order->platform_deduction_id === (int) $p->id) echo 'selected'; ?>>
                                                    {{ $p->platform_name }}
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </form>
                                </td>

                                <td class="px-2 py-2">{{ $order->sender_name ?? '—' }}</td>
                                <td class="px-2 py-2">{{ $order->buyer_name ?? '—' }}</td>
                                <td class="px-2 py-2 font-mono">{{ $order->buyer_phone ?? '—' }}</td>
                                <td class="px-2 py-2 font-mono">{{ $skuDisplay ?: '—' }}</td>

                                <td class="px-2 py-2 text-right font-mono">{{ $fmt($firstSellingPrice ?? 0) }}</td>
                                <td class="px-2 py-2 text-right font-mono font-semibold">{{ $fmt($m['total_jual']) }}</td>
                                <td class="px-2 py-2 text-right font-mono">{{ $fmt($m['total_modal']) }}</td>
                                <td class="px-2 py-2 text-right font-mono">{{ $fmt($m['total_reseller']) }}</td>
                                <td class="px-2 py-2 text-right font-mono">{{ $fmt($m['ongkir_cargo']) }}</td>
                                <td class="px-2 py-2 text-right font-mono">{{ $fmt($m['yield_rp']) }}</td>
                                <td class="px-2 py-2 text-right font-mono">{{ $fmt($m['plastik_dus']) }}</td>
                                <td class="px-2 py-2 text-right font-mono">{{ $fmt($m['operasional_rp']) }}</td>
                                <td class="px-2 py-2 text-right">
                                    <div>{{ $pct($m['adm_pct']) }}</div>
                                    <div class="text-gray-400 text-[10px] font-mono">{{ $fmt($m['adm_rp']) }}</div>
                                </td>
                                <td class="px-2 py-2 text-right">
                                    <div>{{ $pct($m['ongkir_free_pct']) }}</div>
                                    <div class="text-gray-400 text-[10px] font-mono">{{ $fmt($m['ongkir_free_rp']) }}</div>
                                </td>
                                <td class="px-2 py-2 text-right font-mono">{{ $fmt($m['bulat_max']) }}</td>
                                <td class="px-2 py-2 text-right font-mono">{{ $fmt($m['biaya_layanan']) }}</td>
                                <td class="px-2 py-2 text-right font-mono">{{ $fmt($m['biaya_logistik']) }}</td>
                                <td class="px-2 py-2 text-right">
                                    <div>{{ $pct($m['pajak_pct']) }}</div>
                                    <div class="text-gray-400 text-[10px] font-mono">{{ $fmt($m['pajak_rp']) }}</div>
                                </td>
                                <td class="px-2 py-2 text-right font-mono font-semibold {{ $profitClass }}">{{ $fmt($m['profit_kotor']) }}</td>
                                <td class="px-2 py-2 text-right {{ $profitClass }}">{{ $pct($m['pct_profit_kotor']) }}</td>
                                <td class="px-2 py-2 text-right font-mono font-semibold {{ $marginBisnisClass }}">{{ $fmt($m['margin_bisnis']) }}</td>
                                <td class="px-2 py-2 text-right {{ $marginBisnisClass }}">{{ $pct($m['pct_margin_bisnis']) }}</td>
                                <td class="px-2 py-2 text-right font-mono font-semibold {{ $marginLiveClass }}">{{ $fmt($m['margin_live']) }}</td>
                                <td class="px-2 py-2 text-right {{ $marginLiveClass }}">{{ $pct($m['pct_margin_live']) }}</td>
                                <td class="px-2 py-2 text-right font-mono {{ $marginLiveClass }}">{{ $fmt($m['bersih_margin_live']) }}</td>
                                <td class="px-2 py-2 text-right font-mono font-semibold text-red-600">{{ $fmt($m['total_potongan_aplikasi']) }}</td>

                                <td class="px-2 py-2">
                                    <form method="POST" action="{{ route('orders.update_status', $order) }}" class="inline">
                                        @csrf
                                        <select name="status" onchange="this.form.submit()"
                                                class="text-xs rounded border px-2 py-1 font-semibold
                                                    {{ $order->status === 'pending' ? 'bg-amber-100 text-amber-700 border-amber-300' : '' }}
                                                    {{ $order->status === 'packed' ? 'bg-green-100 text-green-700 border-green-300' : '' }}
                                                    {{ $order->status === 'cancelled' ? 'bg-gray-100 text-gray-600 border-gray-300' : '' }}
                                                    {{ $order->status === 'return' ? 'bg-red-100 text-red-700 border-red-300' : '' }}
                                                    {{ $order->status === 'selesai' ? 'bg-blue-100 text-blue-700 border-blue-300' : '' }}">
                                            <option value="pending" {{ $order->status === 'pending' ? 'selected' : '' }}>Pending</option>
                                            <option value="packed" {{ $order->status === 'packed' ? 'selected' : '' }}>Packed</option>
                                            <option value="cancelled" {{ $order->status === 'cancelled' ? 'selected' : '' }}>Cancelled</option>
                                            <option value="return" {{ $order->status === 'return' ? 'selected' : '' }}>Return</option>
                                            <option value="selesai" {{ $order->status === 'selesai' ? 'selected' : '' }}>Selesai</option>
                                        </select>
                                    </form>
                                </td>

                                <td class="px-2 py-2 text-right whitespace-nowrap">
                                    <a href="{{ route('orders.show', $order) }}" class="text-indigo-600 hover:underline">Detail</a>
                                    <span class="text-gray-300">·</span>
                                    <a href="{{ route('orders.edit', $order) }}" class="text-sky-600 hover:underline">Edit</a>
                                    <span class="text-gray-300">·</span>
                                    <form method="POST" action="{{ route('orders.destroy', $order) }}" class="inline"
                                          onsubmit="return confirm('Hapus pesanan {{ $order->resi_number }}?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-red-600 hover:underline">Hapus</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $orders->links() }}</div>
    </div>
@endsection
