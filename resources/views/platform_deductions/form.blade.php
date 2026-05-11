@extends('layouts.app')
@section('title', $deduction->exists ? 'Edit Potongan' : 'Tambah Potongan')

@section('content')
    @php($header = $deduction->exists ? 'Edit Potongan: '.$deduction->platform_name : 'Tambah Potongan Platform')

    <div class="card max-w-4xl">
        <form method="POST"
              action="{{ $deduction->exists ? route('platform_deductions.update', $deduction) : route('platform_deductions.store') }}"
              class="space-y-5">
            @csrf
            @if ($deduction->exists) @method('PUT') @endif

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="md:col-span-2">
                    <label class="label">Nama Platform</label>
                    <input name="platform_name" value="{{ old('platform_name', $deduction->platform_name) }}"
                           required class="input" placeholder="Contoh: TikTok Ranco">
                    @error('platform_name')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                </div>
                <div class="flex items-end">
                    <label class="inline-flex items-center gap-2 text-sm">
                        <input type="checkbox" name="is_active" value="1"
                               @checked(old('is_active', $deduction->is_active ?? true))
                               class="rounded border-gray-300">
                        Aktif
                    </label>
                </div>
            </div>

            <div>
                <h3 class="text-sm font-semibold text-gray-700 mb-2 pb-1 border-b">Potongan Persentase (%)</h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    @foreach ([
                        'adm_percent' => 'ADM (%)',
                        'cashback_percent' => 'CB/BP (%)',
                        'free_shipping_percent' => 'Ongkir Free (%)',
                        'yield_percent' => 'Yield (%)',
                        'operational_percent' => 'Operasional (%)',
                        'tax_percent' => 'Pajak (%)',
                    ] as $field => $label)
                        <div>
                            <label class="label">{{ $label }}</label>
                            <input type="number" step="0.0001" min="0" max="100"
                                   name="{{ $field }}"
                                   value="{{ old($field, (float) ($deduction->$field ?? 0)) }}"
                                   class="input text-right" placeholder="0">
                            @error($field)<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                        </div>
                    @endforeach
                </div>
            </div>

            <div>
                <h3 class="text-sm font-semibold text-gray-700 mb-2 pb-1 border-b">Biaya Nominal (Rp)</h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    @foreach ([
                        'shipping_cargo_amount' => 'Ongkir Cargo (Rp)',
                        'label_amount' => 'Label (Rp)',
                        'packaging_amount' => 'Plastik/Lakban/Dus (Rp)',
                        'service_fee_amount' => 'Biaya Layanan (Rp)',
                        'logistics_amount' => 'Biaya Logistik (Rp)',
                    ] as $field => $label)
                        <div>
                            <label class="label">{{ $label }}</label>
                            <input type="number" step="1" min="0"
                                   name="{{ $field }}"
                                   value="{{ old($field, (int) ($deduction->$field ?? 0)) }}"
                                   class="input text-right" placeholder="0">
                            @error($field)<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="flex gap-2 pt-3 border-t">
                <button class="btn-primary" type="submit">Simpan</button>
                <a href="{{ route('platform_deductions.index') }}" class="btn-secondary">Batal</a>
            </div>
        </form>
    </div>
@endsection
