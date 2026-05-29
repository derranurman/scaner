{{--
    Print-friendly view untuk Laporan Packing. Tidak meng-extend layout
    aplikasi (tanpa Tailwind / nav / sidebar) supaya output cetak bersih.
    Auto memanggil `window.print()` saat dimuat — user bisa pilih
    "Save as PDF" sebagai destination di dialog cetak browser untuk
    menghasilkan file PDF.
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Laporan Packing — {{ $from->format('d M Y') }} s/d {{ $to->format('d M Y') }}</title>
    <style>
        * { box-sizing: border-box; }
        html, body {
            margin: 0;
            padding: 0;
            font-family: 'Helvetica Neue', Arial, sans-serif;
            color: #111;
            font-size: 11px;
        }
        body { padding: 24px; }
        h1 {
            margin: 0 0 4px;
            font-size: 18px;
            letter-spacing: -0.01em;
        }
        h2 {
            margin: 24px 0 8px;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #333;
            border-bottom: 1px solid #999;
            padding-bottom: 4px;
        }
        .meta {
            color: #555;
            font-size: 11px;
            margin-bottom: 4px;
        }
        .meta strong { color: #111; }
        .filters {
            margin-top: 8px;
            font-size: 10px;
            color: #444;
        }
        .filters span {
            display: inline-block;
            border: 1px solid #ccc;
            padding: 2px 6px;
            border-radius: 3px;
            margin-right: 4px;
            background: #f5f5f5;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 8px;
        }
        thead th {
            background: #f0f0f0;
            text-align: left;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            padding: 6px 8px;
            border-bottom: 1.5px solid #333;
            color: #222;
        }
        tbody td {
            padding: 6px 8px;
            border-bottom: 1px solid #ddd;
            vertical-align: top;
        }
        tbody tr:nth-child(even) td { background: #fafafa; }
        .right { text-align: right; }
        .num { font-variant-numeric: tabular-nums; }
        .mono { font-family: 'Consolas', 'Menlo', monospace; font-size: 10px; }
        .muted { color: #777; }
        .item-list {
            margin: 0;
            padding-left: 14px;
            font-size: 10px;
        }
        .item-list li { margin: 1px 0; }
        .empty {
            text-align: center;
            padding: 16px;
            color: #888;
            font-style: italic;
        }
        .footer {
            margin-top: 16px;
            font-size: 9px;
            color: #888;
            text-align: right;
        }
        .toolbar {
            position: fixed;
            top: 12px;
            right: 12px;
            background: #111;
            color: #fff;
            border-radius: 6px;
            padding: 6px 10px;
            font-size: 11px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.2);
        }
        .toolbar button {
            background: #fff;
            color: #111;
            border: 0;
            border-radius: 4px;
            padding: 4px 10px;
            font-size: 11px;
            cursor: pointer;
            margin-left: 6px;
        }
        @media print {
            .toolbar { display: none; }
            body { padding: 0; }
            thead { display: table-header-group; }
            tr { page-break-inside: avoid; }
            tbody tr:nth-child(even) td { background: transparent; }
        }
        @page { margin: 14mm 12mm; }
    </style>
</head>
<body>
    <div class="toolbar">
        Tip: pilih <strong>"Save as PDF"</strong> sebagai destination di dialog cetak.
        <button type="button" onclick="window.print()">Cetak ulang</button>
        <button type="button" onclick="window.close()">Tutup</button>
    </div>

    <h1>Laporan Packing</h1>
    <div class="meta">
        <strong>{{ $brand->app_name ?? config('app.name') }}</strong>
        &nbsp;&middot;&nbsp;
        Periode: <strong>{{ $from->format('d M Y') }}</strong> s/d <strong>{{ $to->format('d M Y') }}</strong>
        &nbsp;&middot;&nbsp;
        Dicetak: {{ now()->format('d M Y H:i') }}
    </div>
    @if ($userName || $type)
        <div class="filters">
            @if ($userName)<span>User: {{ $userName }}</span>@endif
            @if ($type)<span>Jenis: {{ $type }}</span>@endif
        </div>
    @endif

    <h2>Ringkasan per User</h2>
    <table>
        <thead>
            <tr>
                <th>User</th>
                <th class="right">Total Pesanan</th>
                <th class="right">Total Item (pcs)</th>
                <th class="right">Total SKU</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($summary as $row)
                <tr>
                    <td>{{ $row->user?->name ?? '—' }}</td>
                    <td class="right num">{{ number_format($row->total_orders) }}</td>
                    <td class="right num"><strong>{{ number_format($row->total_items) }}</strong></td>
                    <td class="right num">{{ number_format($row->total_distinct) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="empty">Tidak ada aktivitas packing pada rentang ini.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Detail Scan ({{ number_format($logs->count()) }} scan)</h2>
    <table>
        <thead>
            <tr>
                <th style="width: 90px;">Waktu</th>
                <th style="width: 100px;">User</th>
                <th style="width: 110px;">Resi</th>
                <th>Item (Kelengkapan)</th>
                <th class="right" style="width: 40px;">Qty</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($logs as $log)
                <tr>
                    <td class="mono">{{ $log->scanned_at->format('d M Y H:i') }}</td>
                    <td>{{ $log->user?->name ?? '—' }}</td>
                    <td class="mono">{{ $log->resi_number }}</td>
                    <td>
                        @if ($log->order && $log->order->items->count())
                            <ul class="item-list">
                                @foreach ($log->order->items as $item)
                                    <li>
                                        {{ $item->quantity }}&times;
                                        {{ $item->product_name ?? '—' }}
                                        @if ($item->variant_name)— {{ $item->variant_name }}@endif
                                        @if ($item->sku)<span class="muted mono">[{{ $item->sku }}]</span>@endif
                                    </li>
                                @endforeach
                            </ul>
                        @else
                            <span class="muted">—</span>
                        @endif
                    </td>
                    <td class="right num"><strong>{{ $log->total_items }}</strong></td>
                </tr>
            @empty
                <tr><td colspan="5" class="empty">Tidak ada data scan pada rentang ini.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        Dihasilkan otomatis oleh {{ $brand->app_name ?? config('app.name') }} —
        {{ now()->format('Y-m-d H:i:s') }}
    </div>

    <script>
        // Auto-trigger print dialog setelah halaman di-render. User tinggal
        // pilih "Save as PDF" sebagai destination untuk mendapat file PDF.
        window.addEventListener('load', function () {
            setTimeout(function () { window.print(); }, 250);
        });
    </script>
</body>
</html>
