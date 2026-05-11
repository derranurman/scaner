@extends('layouts.app')
@section('title', 'Kelola Potongan')

@section('content')
    @php($header = 'Kelola Potongan Platform')
    @php($subheader = 'Biaya & persentase potongan per marketplace. Dipakai untuk hitung profit bersih per pesanan.')

    <div class="card">
        <div class="flex items-center justify-between mb-4">
            <div class="text-sm text-gray-500">
                Total: <b>{{ $deductions->count() }}</b> platform terdaftar
            </div>
            <a href="{{ route('platform_deductions.create') }}" class="btn-primary">+ Tambah Platform</a>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm whitespace-nowrap">
                <thead class="text-left text-xs uppercase text-gray-500 border-b">
                    <tr>
                        <th class="py-2 pr-3">No</th>
                        <th class="py-2 pr-3">Platform</th>
                        <th class="py-2 pr-3 text-right">ADM (%)</th>
                        <th class="py-2 pr-3 text-right">CB/BP (%)</th>
                        <th class="py-2 pr-3 text-right">Ongkir Free (%)</th>
                        <th class="py-2 pr-3 text-right">Ongkir Cargo (Rp)</th>
                        <th class="py-2 pr-3 text-right">Label (Rp)</th>
                        <th class="py-2 pr-3 text-right">Yield (%)</th>
                        <th class="py-2 pr-3 text-right">Plastik/Lakban/Dus (Rp)</th>
                        <th class="py-2 pr-3 text-right">Operasional (%)</th>
                        <th class="py-2 pr-3 text-right">Biaya Layanan (Rp)</th>
                        <th class="py-2 pr-3 text-right">Biaya Logistik (Rp)</th>
                        <th class="py-2 pr-3 text-right">Pajak (%)</th>
                        <th class="py-2 pr-3 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse ($deductions as $i => $d)
                        <tr>
                            <td class="py-3 pr-3">{{ $i + 1 }}</td>
                            <td class="py-3 pr-3 font-medium">
                                {{ $d->platform_name }}
                                @if (! $d->is_active)
                                    <span class="badge bg-gray-100 text-gray-600 ml-1">Nonaktif</span>
                                @endif
                            </td>
                            <td class="py-3 pr-3 text-right">{{ rtrim(rtrim(number_format((float) $d->adm_percent, 2, '.', ''), '0'), '.') }}%</td>
                            <td class="py-3 pr-3 text-right">{{ rtrim(rtrim(number_format((float) $d->cashback_percent, 2, '.', ''), '0'), '.') }}%</td>
                            <td class="py-3 pr-3 text-right">{{ rtrim(rtrim(number_format((float) $d->free_shipping_percent, 2, '.', ''), '0'), '.') }}%</td>
                            <td class="py-3 pr-3 text-right font-mono text-xs">Rp {{ number_format((float) $d->shipping_cargo_amount, 0, ',', '.') }}</td>
                            <td class="py-3 pr-3 text-right font-mono text-xs">Rp {{ number_format((float) $d->label_amount, 0, ',', '.') }}</td>
                            <td class="py-3 pr-3 text-right">{{ rtrim(rtrim(number_format((float) $d->yield_percent, 2, '.', ''), '0'), '.') }}%</td>
                            <td class="py-3 pr-3 text-right font-mono text-xs">Rp {{ number_format((float) $d->packaging_amount, 0, ',', '.') }}</td>
                            <td class="py-3 pr-3 text-right">{{ rtrim(rtrim(number_format((float) $d->operational_percent, 2, '.', ''), '0'), '.') }}%</td>
                            <td class="py-3 pr-3 text-right font-mono text-xs">Rp {{ number_format((float) $d->service_fee_amount, 0, ',', '.') }}</td>
                            <td class="py-3 pr-3 text-right font-mono text-xs">Rp {{ number_format((float) $d->logistics_amount, 0, ',', '.') }}</td>
                            <td class="py-3 pr-3 text-right">{{ rtrim(rtrim(number_format((float) $d->tax_percent, 2, '.', ''), '0'), '.') }}%</td>
                            <td class="py-3 pr-3 text-right">
                                <a href="{{ route('platform_deductions.edit', $d) }}" class="text-indigo-600 hover:underline">Edit</a>
                                <form method="POST" action="{{ route('platform_deductions.destroy', $d) }}" class="inline ml-2"
                                      onsubmit="return confirm('Hapus potongan platform {{ $d->platform_name }}?');">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="text-red-600 hover:underline">Hapus</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="14" class="py-6 text-center text-gray-500">
                            Belum ada data. <a href="{{ route('platform_deductions.create') }}" class="text-indigo-600 hover:underline">Tambah sekarang →</a>
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
