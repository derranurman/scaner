@extends('layouts.app')
@section('title', 'Pratinjau Import PDF')

@section('content')
    <?php $header = 'Pratinjau Import PDF'; ?>
    <?php $subheader = 'Cek hasil parse untuk tiap halaman. Centang yang ingin disimpan.'; ?>

    <div class="card mb-6">
        <div class="flex flex-wrap gap-4 items-center justify-between text-sm">
            <div>
                <div><span class="text-gray-500">File:</span> <b>{{ $draft->original_filename }}</b></div>
                <div><span class="text-gray-500">Halaman:</span> {{ $draft->total_pages }} · Diupload {{ $draft->created_at->diffForHumans() }}</div>
            </div>
            <div class="flex gap-2">
                <form method="POST" action="{{ route('orders.import.pdf.discard', $draft) }}"
                      onsubmit="return confirm('Buang draft ini? Tidak akan disimpan sebagai pesanan.');">
                    @csrf
                    @method('DELETE')
                    <button class="btn-secondary" type="submit">Buang Draft</button>
                </form>
            </div>
        </div>
    </div>

    <form method="POST" action="{{ route('orders.import.pdf.commit', $draft) }}">
        @csrf

        <div class="card mb-4 flex flex-wrap items-center gap-3">
            <label class="inline-flex items-center gap-2 text-sm">
                <input type="checkbox" id="select-all" checked class="rounded border-gray-300">
                Pilih semua
            </label>
            <span class="text-xs text-gray-500">
                Kuning = resi sudah ada (akan diupdate). Merah = ada item belum ke-mapping.
            </span>
            <div class="ml-auto">
                <button type="submit" class="btn-primary">Simpan Pesanan yang Dicentang</button>
            </div>
        </div>

        <div class="space-y-4">
            <?php foreach ($draft->parsed_orders as $idx => $entry): ?>
                <?php
                    $hasUnmatched = false;
                    foreach ($entry['items'] as $it) {
                        if (($it['source'] ?? null) === 'unmatched' || empty($it['variant_id'])) {
                            $hasUnmatched = true;
                            break;
                        }
                    }
                    $rowClass = $hasUnmatched
                        ? 'border-red-200 bg-red-50/40'
                        : ($entry['already_exists'] ? 'border-amber-200 bg-amber-50/40' : 'border-gray-200 bg-white');
                ?>
                <div class="rounded-xl border {{ $rowClass }} p-4">
                    <div class="flex flex-wrap items-start gap-3">
                        <label class="pt-1">
                            <input type="checkbox" name="selected[]" value="{{ $idx }}"
                                   class="item-check rounded border-gray-300 h-4 w-4"
                                   {{ $hasUnmatched ? '' : 'checked' }}>
                        </label>

                        <div class="flex-1 min-w-[280px]">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="text-xs text-gray-500">Hal. {{ $entry['page'] }}</span>
                                <span class="font-mono text-lg font-bold">{{ $entry['resi_number'] ?: '—' }}</span>
                                <?php $mp = $entry['marketplace'] ?? 'tiktok'; ?>
                                <?php if ($mp === 'shopee'): ?>
                                    <span class="badge bg-orange-100 text-orange-700">Shopee / SPX</span>
                                <?php else: ?>
                                    <span class="badge bg-indigo-100 text-indigo-700">TikTok / J&amp;T</span>
                                <?php endif; ?>
                                <?php if ($entry['already_exists']): ?>
                                    <span class="badge bg-amber-100 text-amber-700">Sudah ada &middot; akan update</span>
                                <?php endif; ?>
                                <?php if ($entry['matched_keyword']): ?>
                                    <span class="badge bg-indigo-100 text-indigo-700">Combo: {{ $entry['matched_keyword'] }}</span>
                                <?php endif; ?>
                            </div>

                            {{-- Info mentah dari label PDF (bukan hasil resolusi) --}}
                            <dl class="mt-2 text-xs space-y-1">
                                <div class="flex">
                                    <dt class="w-28 text-gray-500 shrink-0">Pengirim</dt>
                                    <dd class="font-medium">{{ $entry['sender_name'] ?? '—' }}</dd>
                                </div>
                                <div class="flex">
                                    <dt class="w-28 text-gray-500 shrink-0">Resi</dt>
                                    <dd class="font-mono font-semibold">{{ $entry['resi_number'] ?: '—' }}</dd>
                                </div>
                                <div class="flex">
                                    <dt class="w-28 text-gray-500 shrink-0">Order ID</dt>
                                    <dd class="font-mono">{{ $entry['tiktok_order_id'] ?? '—' }}</dd>
                                </div>
                                <div class="flex items-start">
                                    <dt class="w-28 text-gray-500 shrink-0">Product Name</dt>
                                    <dd class="flex-1">
                                        <?php $pRows = $entry['product_rows'] ?? []; ?>
                                        <?php if (empty($pRows)): ?>
                                            <span class="text-gray-400">—</span>
                                        <?php else: ?>
                                            <ul class="space-y-0.5">
                                                <?php foreach ($pRows as $pr): ?>
                                                    <li>{{ $pr['product_name'] ?? '—' }}</li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>
                                    </dd>
                                </div>
                                <?php if (!empty($entry['barang_keyword'])): ?>
                                    <div class="flex">
                                        <dt class="w-28 text-gray-500 shrink-0">Jumlah &amp; Barang</dt>
                                        <?php
                                            $totalLabelQty = 0;
                                            foreach ($pRows as $pr) {
                                                $totalLabelQty += (int) ($pr['quantity'] ?? 0);
                                            }
                                            if ($totalLabelQty <= 0) $totalLabelQty = 1;
                                        ?>
                                        <dd><span class="font-medium">{{ $totalLabelQty }}pcs</span>, {{ $entry['barang_keyword'] }}</dd>
                                    </div>
                                <?php endif; ?>
                                <div class="flex items-start">
                                    <dt class="w-28 text-gray-500 shrink-0">SKU</dt>
                                    <dd class="flex-1 font-mono">
                                        <?php
                                            $skuParts = [];
                                            foreach ($pRows as $pr) {
                                                if (!empty($pr['sku'])) $skuParts[] = $pr['sku'];
                                                elseif (!empty($pr['seller_sku'])) $skuParts[] = $pr['seller_sku'];
                                            }
                                            $skuJoined = implode(' · ', array_unique($skuParts));
                                        ?>
                                        {{ $skuJoined !== '' ? $skuJoined : '—' }}
                                    </dd>
                                </div>
                                <div class="flex items-start">
                                    <dt class="w-28 text-gray-500 shrink-0">Seller Note</dt>
                                    <dd class="flex-1">
                                        <?php if (!empty($entry['seller_note'])): ?>
                                            <span class="font-mono font-semibold text-indigo-600">{{ $entry['seller_note'] }}</span>
                                        <?php else: ?>
                                            <span class="text-gray-400">—</span>
                                        <?php endif; ?>
                                    </dd>
                                </div>
                            </dl>

                            {{-- Penerima & alamat (secondary, lebih redup) --}}
                            <div class="mt-2 text-xs text-gray-600">
                                Penerima: <span class="font-medium">{{ $entry['buyer_name'] ?? '—' }}</span>
                                <?php if (!empty($entry['buyer_phone'])): ?>
                                    &middot; <span class="text-gray-500">{{ $entry['buyer_phone'] }}</span>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($entry['shipping_address'])): ?>
                                <div class="text-[11px] text-gray-500">{{ $entry['shipping_address'] }}</div>
                            <?php endif; ?>
                            <div class="text-[11px] text-gray-400 mt-1">
                                {{ $entry['courier'] }} &middot; {{ $entry['weight'] ?? '—' }} &middot; {{ $entry['order_date'] ?? '—' }}
                            </div>

                            <?php if (!empty($entry['raw_text'] ?? null) || empty($entry['items'])): ?>
                                <details class="mt-2">
                                    <summary class="text-xs text-gray-400 cursor-pointer hover:text-gray-700">Lihat Teks Mentah PDF (debug)</summary>
                                    <pre class="mt-1 text-[10px] bg-gray-50 border border-gray-200 rounded p-2 max-h-40 overflow-auto whitespace-pre-wrap font-mono text-gray-600">{{ $entry['raw_text'] ?? '(tidak tersimpan)' }}</pre>
                                </details>
                            <?php endif; ?>
                        </div>

                        <div class="flex-1 min-w-[280px]">
                            <div class="text-xs font-semibold uppercase text-gray-500 mb-1">Item Setelah Resolusi</div>

                            {{-- Seller Note ditampilkan di kolom resolusi supaya kelihatan saat cek hasil mapping --}}
                            <?php if (!empty($entry['seller_note'])): ?>
                                <div class="mb-2 text-xs bg-purple-50 border border-purple-200 rounded px-2 py-1.5">
                                    <span class="font-semibold text-purple-700 uppercase tracking-wide mr-1">Seller Note</span>
                                    <span class="font-mono font-semibold text-purple-900">{{ $entry['seller_note'] }}</span>
                                </div>
                            <?php endif; ?>

                            <?php if (empty($entry['items'])): ?>
                                <div class="text-xs text-red-600">Tidak ada item terdeteksi.</div>
                            <?php else: ?>
                                <ul class="text-sm divide-y">
                                    <?php foreach ($entry['items'] as $item): ?>
                                        <?php
                                            $source = $item['source'] ?? 'unmatched';
                                            $sourceBadge = match ($source) {
                                                'combo'       => ['combo', 'bg-indigo-100 text-indigo-700'],
                                                'sku'         => ['SKU match', 'bg-green-100 text-green-700'],
                                                'name'        => ['nama match', 'bg-sky-100 text-sky-700'],
                                                'seller_note' => ['seller note', 'bg-purple-100 text-purple-700'],
                                                default       => ['perlu mapping', 'bg-red-100 text-red-700'],
                                            };
                                        ?>
                                        <li class="py-1 flex items-center gap-2 flex-wrap">
                                            <span class="badge bg-gray-100 text-gray-700">{{ $item['quantity'] }}&times;</span>
                                            <span class="flex-1">
                                                <span class="font-medium">{{ $item['product_name'] }}</span>
                                                <?php if (!empty($item['variant_name'])): ?>
                                                    &mdash; {{ $item['variant_name'] }}
                                                <?php endif; ?>
                                                <?php if (!empty($item['sku'])): ?>
                                                    <span class="text-xs text-gray-400 font-mono ml-1">{{ $item['sku'] }}</span>
                                                <?php endif; ?>
                                                <?php if (!empty($item['matched_keyword']) && $source !== 'combo'): ?>
                                                    <div class="text-[10px] text-gray-500 mt-0.5">{{ $item['matched_keyword'] }}</div>
                                                <?php endif; ?>
                                            </span>
                                            <span class="badge {{ $sourceBadge[1] }}">{{ $sourceBadge[0] }}</span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>

                            <?php if (!empty($entry['warnings'])): ?>
                                <div class="mt-2 text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded px-2 py-1">
                                    <ul class="list-disc list-inside">
                                        <?php foreach ($entry['warnings'] as $w): ?>
                                            <li>{{ $w }}</li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="mt-6 flex justify-end gap-2">
            <a href="{{ route('combo_mappings.index') }}" class="btn-secondary">Buka Combo Mapping</a>
            <button type="submit" class="btn-primary">Simpan Pesanan yang Dicentang</button>
        </div>
    </form>

    <script>
        document.getElementById('select-all').addEventListener('change', function (e) {
            document.querySelectorAll('.item-check').forEach(function (c) {
                c.checked = e.target.checked;
            });
        });
    </script>
@endsection
