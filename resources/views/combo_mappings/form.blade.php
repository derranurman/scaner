@extends('layouts.app')
@section('title', $mapping->exists ? 'Edit Combo Mapping' : 'Combo Mapping Baru')

@section('content')
    @php($header = $mapping->exists ? 'Edit Combo Mapping' : 'Combo Mapping Baru')

    <div class="card max-w-3xl" x-data="comboForm({{ json_encode(
            $mapping->exists
                ? $mapping->items->map(fn ($i) => ['variant_id' => $i->variant_id, 'quantity' => $i->quantity])->values()
                : [['variant_id' => '', 'quantity' => 1]]
        ) }})">
        <form method="POST" action="{{ $mapping->exists ? route('combo_mappings.update', $mapping) : route('combo_mappings.store') }}" class="space-y-4">
            @csrf
            @if ($mapping->exists) @method('PUT') @endif

            <div>
                <label class="label">Keyword (harus unik)</label>
                <input name="keyword" value="{{ old('keyword', $mapping->keyword) }}" required class="input font-mono"
                       placeholder="contoh: Stir+Bosskit">
                <p class="text-xs text-gray-500 mt-1">Teks ini akan dicari (case-insensitive) di dalam teks "Barang : …" pada label.</p>
                @error('keyword')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
            </div>

            <div>
                <label class="label">Deskripsi (opsional)</label>
                <input name="description" value="{{ old('description', $mapping->description) }}" class="input" placeholder="Bundle paket stir + boskit untuk promo">
            </div>

            <div>
                <label class="label">Varian yang dikurangi</label>
                <div class="space-y-2">
                    <template x-for="(item, i) in items" :key="i">
                        <div class="flex gap-2 items-center">
                            <select :name="'items[' + i + '][variant_id]'" x-model="item.variant_id" required class="input flex-1">
                                <option value="">— pilih varian —</option>
                                @foreach ($variants as $v)
                                    <option value="{{ $v->id }}">{{ $v->product?->name }} — {{ $v->name }} ({{ $v->sku }})</option>
                                @endforeach
                            </select>
                            <input type="number" min="1" :name="'items[' + i + '][quantity]'" x-model.number="item.quantity" required class="input w-20 text-center" placeholder="Qty">
                            <button type="button" class="btn-secondary" @click="remove(i)" x-show="items.length > 1">−</button>
                        </div>
                    </template>
                </div>
                <button type="button" class="btn-secondary mt-2" @click="add()">+ Tambah Varian</button>
                @error('items')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
            </div>

            <div class="flex gap-2 pt-3 border-t">
                <button class="btn-primary" type="submit">Simpan</button>
                <a href="{{ route('combo_mappings.index') }}" class="btn-secondary">Batal</a>
            </div>
        </form>
    </div>

    <script>
        function comboForm(initial) {
            return {
                items: initial.length ? initial : [{ variant_id: '', quantity: 1 }],
                add() { this.items.push({ variant_id: '', quantity: 1 }); },
                remove(i) { this.items.splice(i, 1); },
            };
        }
    </script>
@endsection
