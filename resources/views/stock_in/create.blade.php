@extends('layouts.app')
@section('title', 'Input Barang Masuk')

@section('content')
    @php($header = 'Input Barang Masuk')
    @php($subheader = 'Catat penerimaan stok dari supplier / restock. Bisa input banyak varian sekaligus.')

    <div class="card max-w-4xl"
         x-data="stockInApp({{ $variants->toJson() }})">
        <form method="POST" action="{{ route('stock_in.store') }}" class="space-y-4">
            @csrf

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="label">Nomor Referensi (opsional)</label>
                    <input name="reference" value="{{ old('reference') }}" class="input" placeholder="Contoh: PO-2026-0012">
                    @error('reference')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="label">Catatan (opsional)</label>
                    <input name="note" value="{{ old('note') }}" class="input" placeholder="Contoh: Restock dari PT Aksesoris">
                    @error('note')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                </div>
            </div>

            <div>
                <label class="label">Daftar Barang Masuk</label>
                <div class="space-y-2">
                    <template x-for="(item, i) in items" :key="i">
                        <div class="flex flex-wrap gap-2 items-center">
                            <select :name="'items[' + i + '][variant_id]'" x-model.number="item.variant_id" required
                                    class="input flex-1 min-w-[280px]">
                                <option value="">— pilih varian —</option>
                                <template x-for="v in variants" :key="v.id">
                                    <option :value="v.id" x-text="v.label"></option>
                                </template>
                            </select>
                            <input type="number" min="1" step="1" :name="'items[' + i + '][quantity]'" x-model.number="item.quantity" required
                                   class="input w-24 text-center" placeholder="Qty">
                            <button type="button" class="btn-secondary" @click="remove(i)" x-show="items.length > 1">−</button>
                        </div>
                    </template>
                </div>
                <button type="button" class="btn-secondary mt-2" @click="add()">+ Tambah Baris</button>
                @error('items')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                @error('items.*.variant_id')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                @error('items.*.quantity')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
            </div>

            <div class="rounded-lg bg-indigo-50 border border-indigo-200 px-3 py-2 text-sm text-indigo-800">
                Total akan ditambah:
                <span class="font-semibold" x-text="totalQty()"></span> pcs
                dalam <span class="font-semibold" x-text="items.filter(i => i.variant_id && i.quantity).length"></span> varian.
            </div>

            <div class="flex gap-2 pt-3 border-t">
                <button class="btn-primary" type="submit">Simpan Barang Masuk</button>
                <a href="{{ route('products.index') }}" class="btn-secondary">Batal</a>
            </div>
        </form>
    </div>

    <script>
        function stockInApp(variants) {
            return {
                variants,
                items: [{ variant_id: '', quantity: 1 }],
                add() { this.items.push({ variant_id: '', quantity: 1 }); },
                remove(i) { this.items.splice(i, 1); },
                totalQty() {
                    return this.items.reduce((sum, it) => sum + (parseInt(it.quantity) || 0), 0);
                },
            };
        }
    </script>
@endsection
