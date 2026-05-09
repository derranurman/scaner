@extends('layouts.app')
@section('title', 'Pratinjau Import PDF')

@section('content')
    @php($header = 'Pratinjau Import PDF')
    @php($subheader = 'Cek hasil parse untuk tiap halaman. Centang yang ingin disimpan.')

    <div class="card mb-6">
        <div class="flex flex-wrap gap-4 items-center justify-between text-sm">
            <div>
                <div><span class="text-gray-500">File:</span> <b>{{ $draft->original_filename }}</b></div>
                <div><span class="text-gray-500">Halaman:</span> {{ $draft->total_pages }} · Diupload {{ $draft->created_at->diffForHumans() }}</div>
            </div>
            <div class="flex gap-2">
                <form method="POST" action="{{ route('orders.import.pdf.discard', $draft) }}"
                      onsubmit="return confirm('Buang draft ini? Tidak akan disimpan sebagai pesanan.');">
                    @csrf @method('DELETE')
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
            @foreach ($draft->parsed_orders as $idx => $entry)
                @php
                    $hasUnmatched = collect($entry['items'])->contains(fn ($i) => ($i['source'] ?? null) === 'unmatched' || empty($i['variant_id']));
                    $rowClass = $hasUnmatched
                        ? 'border-red-200 bg-red-50/40'
                        : ($entry['already_exists'] ? 'border-amber-200 bg-amber-50/40' : 'border-gray-200 bg-white');
                @endphp

                <div class="rounded-xl border {{ $rowClass }} p-4">
                    <div class="flex flex-wrap items-start gap-3">
                        <label class="pt-1">
                            <input type="checkbox" name="selected[]" value="{{ $idx }}"
                                   class="item-check rounded border-gray-300 h-4 w-4"
                                   {{ $hasUnmatched ? '' : 'checked' }}>
                        </label>
                        <div class="flex-1 min-w-[280px]">
                            <div class="flex items-center gap-2">
                                <span class="text-xs text-gray-500">Hal. {{ $entry['page'] }}</span>
                                <span class="font-mono text-lg font-bold">{{ $entry['resi_number'] ?: '—' }}</span>
                                @if ($entry['already_exists'])
                                    <span class="badge bg-amber-100 text-amber-700">Sudah ada · akan update</span>
                                @endif
                                @if ($entry['matched_keyword'])
                                    <span class="badge bg-indigo-100 text-indigo-700">Combo: {{ $entry['matched_keyword'] }}</span>
                                @endif
                            </div>
                            <div class="text-sm text-gray-700 mt-1">
                                {{ $entry['buyer_name'] ?? '—' }}
                                @if ($entry['buyer_phone']) · <span class="text-gray-500">{{ $entry['buyer_phone'] }}</span> @endif
                            </div>
                            <div class="text-xs text-gray-500">{{ $entry['shipping_address'] ?? '—' }}</div>
                            <div class="text-xs text-gray-400 mt-1">
                                Order ID: {{ $entry['tiktok_order_id'] ?? '—' }} ·
                                {{ $entry['courier'] }} ·
                                {{ $entry['weight'] ?? '—' }} ·
                                {{ $entry['order_date'] ?? '—' }}
                            </div>
                            @if ($entry['barang_keyword'])
                                <div class="text-xs text-gray-500 mt-1">
                                    Barang di label: <span class="font-mono">{{ $entry['barang_keyword'] }}</span>
                                </div>
                            @endif
                        </div>

                        <div class="flex-1 min-w-[280px]">
                            <div class="text-xs font-semibold uppercase text-gray-500 mb-1">Item Setelah Resolusi</div>
                            @if (empty($entry['items']))
                                <div class="text-xs text-red-600">Tidak ada item terdeteksi.</div>
                            @else
                                <ul class="text-sm divide-y">
                                    @foreach ($entry['items'] as $item)
                                        <li class="py-1 flex items-center gap-2">
                                            <span class="badge bg-gray-100 text-gray-700">{{ $item['quantity'] }}×</span>
                                            <span class="flex-1">
                                                <span class="font-medium">{{ $item['product_name'] }}</span>
                                                @if ($item['variant_name']) — {{ $item['variant_name'] }} @endif
                                                @if ($item['sku']) <span class="text-xs text-gray-400 font-mono ml-1">{{ $item['sku'] }}</span> @endif
                                            </span>
                                            @switch($item['source'] ?? 'unmatched')
                                                @case('combo')
                                                    <span class="badge bg-indigo-100 text-indigo-700">combo</span>
                                                    @break
                                                @case('sku')
                                                    <span class="badge bg-green-100 text-green-700">SKU match</span>
                                                    @break
                                                @case('name')
                                                    <span class="badge bg-sky-100 text-sky-700">nama match</span>
                                                    @break
                                                @default
                                                    <span class="badge bg-red-100 text-red-700">perlu mapping</span>
                                            @endswitch
                                        </li>
                                    @endforeach
                                </ul>
                            @endif

                            @if (! empty($entry['warnings']))
                                <div class="mt-2 text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded px-2 py-1">
                                    <ul class="list-disc list-inside">
                                        @foreach ($entry['warnings'] as $w) <li>{{ $w }}</li> @endforeach
                                    </ul>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-6 flex justify-end gap-2">
            <a href="{{ route('combo_mappings.index') }}" class="btn-secondary">Buka Combo Mapping</a>
            <button type="submit" class="btn-primary">Simpan Pesanan yang Dicentang</button>
        </div>
    </form>

    <script>
        document.getElementById('select-all').addEventListener('change', (e) => {
            document.querySelectorAll('.item-check').forEach(c => c.checked = e.target.checked);
        });
    </script>
@endsection
