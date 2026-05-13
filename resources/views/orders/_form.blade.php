{{--
    Partial form untuk create/edit order.
    Expects: $order (null untuk create), $platforms (Collection), $products (Collection).
--}}
@php
    $o = $order ?? null;
    $statusOptions = [
        \App\Models\Order::STATUS_PENDING => 'Pending',
        \App\Models\Order::STATUS_PACKED => 'Packed',
        \App\Models\Order::STATUS_CANCELLED => 'Cancelled',
    ];

    // Kelengkapan options: value => label
    $kelengkapanOptions = [
        '1' => '1 = Stir Saja',
        '2' => '2 = Stir + Boskit',
        '3' => '3 = Boskit Saja',
        '4' => '4 = Spoiler',
        '5' => '5 = Klakson',
        '6' => '6 = Stir + Stir',
        '7' => '7 = Stir + Stir + Boskit',
        '8' => '8 = Stir + Boskit + Boskit',
    ];

    // Mapping kelengkapan => field variant yang harus ditampilkan.
    // Harus sinkron dengan KELENGKAPAN_MAP di JavaScript & controller.
    $kelengkapanFieldMap = [
        '1' => ['stir_1'],
        '2' => ['stir_1', 'boskit_1'],
        '3' => ['boskit_1'],
        '4' => ['spoiler'],
        '5' => ['klakson'],
        '6' => ['stir_1', 'stir_2'],
        '7' => ['stir_1', 'stir_2', 'boskit_1'],
        '8' => ['stir_1', 'boskit_1', 'boskit_2'],
    ];

    // Koleksi variant per kategori.
    // Filter case-insensitive berdasarkan keyword di field "type" ATAU "name"
    // supaya fleksibel: produk dengan type "Stir Motor", "Stir Mobil", "Stir Universal"
    // atau nama "Stir Skeleton" semua masuk ke dropdown Stir.
    $matchByKeyword = function ($keywords) use ($products) {
        $keywords = (array) $keywords;
        return $products->filter(function ($p) use ($keywords) {
            $haystack = strtolower(($p->type ?? '').' '.($p->name ?? ''));
            foreach ($keywords as $kw) {
                if (str_contains($haystack, strtolower($kw))) {
                    return true;
                }
            }
            return false;
        })->values();
    };

    $stirProducts    = $matchByKeyword('stir');
    $boskitProducts  = $matchByKeyword(['boskit', 'bosskit']);
    $spoilerProducts = $matchByKeyword('spoiler');
    $klaksonProducts = $matchByKeyword('klakson');

    // Existing items for edit mode
    $existingItems = $o ? $o->items : collect();
@endphp

<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
    <div>
        <label class="label">Nomor Resi <span class="text-red-500">*</span></label>
        <input type="text" name="resi_number" required maxlength="32"
               value="{{ old('resi_number', $o->resi_number ?? '') }}"
               class="input font-mono" placeholder="JX9374396076">
        @error('resi_number') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>

    <div>
        <label class="label">Order ID</label>
        <input type="text" name="tiktok_order_id" maxlength="64"
               value="{{ old('tiktok_order_id', $o->tiktok_order_id ?? '') }}"
               class="input font-mono" placeholder="584005180715730252">
        @error('tiktok_order_id') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>

    <div>
        <label class="label">Kurir</label>
        <input type="text" name="courier" maxlength="20"
               value="{{ old('courier', $o->courier ?? 'JNT') }}"
               class="input" placeholder="JNT">
        @error('courier') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>

    <div>
        <label class="label">Status</label>
        <select name="status" class="input">
            @foreach ($statusOptions as $val => $label)
                <option value="{{ $val }}"
                    <?php if (old('status', $o->status ?? \App\Models\Order::STATUS_PENDING) === $val) echo 'selected'; ?>>
                    {{ $label }}
                </option>
            @endforeach
        </select>
        @error('status') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>

    <div>
        <label class="label">Pengirim</label>
        <input type="text" name="sender_name" maxlength="150"
               value="{{ old('sender_name', $o->sender_name ?? '') }}"
               class="input" placeholder="ArrozaqAuto96">
        @error('sender_name') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>

    <div>
        <label class="label">Host Live</label>
        <input type="text" name="host_live" maxlength="100"
               value="{{ old('host_live', $o->host_live ?? '') }}"
               class="input" placeholder="Host A">
        @error('host_live') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>

    <div>
        <label class="label">Pembeli</label>
        <input type="text" name="buyer_name" maxlength="150"
               value="{{ old('buyer_name', $o->buyer_name ?? '') }}"
               class="input" placeholder="Nama pembeli">
        @error('buyer_name') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>

    <div>
        <label class="label">No. HP</label>
        <input type="text" name="buyer_phone" maxlength="30"
               value="{{ old('buyer_phone', $o->buyer_phone ?? '') }}"
               class="input font-mono" placeholder="081234567890">
        @error('buyer_phone') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>

    <div>
        <label class="label">Platform</label>
        <select name="platform_deduction_id" class="input">
            <option value="">— tidak dipilih —</option>
            @foreach ($platforms as $p)
                <option value="{{ $p->id }}"
                    <?php if ((int) old('platform_deduction_id', $o->platform_deduction_id ?? 0) === (int) $p->id) echo 'selected'; ?>>
                    {{ $p->platform_name }}
                </option>
            @endforeach
        </select>
        @error('platform_deduction_id') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>

    <div>
        <label class="label">Tanggal Pesanan</label>
        <input type="date" name="order_date"
               value="{{ old('order_date', $o?->order_date ? $o->order_date->format('Y-m-d') : '') }}"
               class="input">
        @error('order_date') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>

    <div class="md:col-span-2">
        <label class="label">Alamat Pengiriman</label>
        <textarea name="shipping_address" rows="2" class="input">{{ old('shipping_address', $o->shipping_address ?? '') }}</textarea>
        @error('shipping_address') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>

    <div class="md:col-span-2">
        <label class="label">Catatan (Seller Note)</label>
        <textarea name="notes" rows="2" class="input">{{ old('notes', $o->notes ?? '') }}</textarea>
        @error('notes') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>
</div>

{{-- ========== SECTION: PILIHAN BARANG (KELENGKAPAN) ========== --}}
<div class="mt-6 border-t pt-4">
    <h3 class="text-sm font-semibold text-gray-700 mb-3">Pilihan Barang</h3>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
            <label class="label">Kelengkapan</label>
            <select name="kelengkapan" id="kelengkapan-select" class="input">
                <option value="">— tidak dipilih —</option>
                @foreach ($kelengkapanOptions as $val => $label)
                    <option value="{{ $val }}"
                        <?php if ((string) old('kelengkapan', $existingItems->first()?->kelengkapan ?? '') === (string) $val) echo 'selected'; ?>>
                        {{ $label }}
                    </option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="label">Jumlah</label>
            <input type="number" name="item_quantity" id="item-quantity" min="1"
                   value="{{ old('item_quantity', $existingItems->first()?->quantity ?? 1) }}"
                   class="input" placeholder="1">
        </div>
    </div>

    {{-- Dynamic variant fields based on kelengkapan --}}
    <div id="kelengkapan-fields" class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">

        {{-- Stir 1 --}}
        <div id="field-stir_1" class="kelengkapan-field hidden">
            <label class="label">Stir 1</label>
            <select name="variant_stir_1" class="input variant-select">
                <option value="">— pilih stir —</option>
                @foreach ($stirProducts as $product)
                    @foreach ($product->variants as $variant)
                        <option value="{{ $variant->id }}"
                                data-price="{{ $product->purchase_price }}"
                                data-selling="{{ $product->selling_price }}">
                            {{ $product->name }} — {{ $variant->name }} ({{ $variant->sku }})
                        </option>
                    @endforeach
                @endforeach
            </select>
            @if ($stirProducts->isEmpty())
                <p class="text-xs text-amber-600 mt-1">Belum ada produk Stir. Tambahkan di halaman Produk (nama/tipe mengandung "stir").</p>
            @endif
        </div>

        {{-- Stir 2 --}}
        <div id="field-stir_2" class="kelengkapan-field hidden">
            <label class="label">Stir 2</label>
            <select name="variant_stir_2" class="input variant-select">
                <option value="">— pilih stir kedua —</option>
                @foreach ($stirProducts as $product)
                    @foreach ($product->variants as $variant)
                        <option value="{{ $variant->id }}"
                                data-price="{{ $product->purchase_price }}"
                                data-selling="{{ $product->selling_price }}">
                            {{ $product->name }} — {{ $variant->name }} ({{ $variant->sku }})
                        </option>
                    @endforeach
                @endforeach
            </select>
        </div>

        {{-- Boskit 1 --}}
        <div id="field-boskit_1" class="kelengkapan-field hidden">
            <label class="label">Boskit 1</label>
            <select name="variant_boskit_1" class="input variant-select">
                <option value="">— pilih boskit —</option>
                @foreach ($boskitProducts as $product)
                    @foreach ($product->variants as $variant)
                        <option value="{{ $variant->id }}"
                                data-price="{{ $product->purchase_price }}"
                                data-selling="{{ $product->selling_price }}">
                            {{ $product->name }} — {{ $variant->name }} ({{ $variant->sku }})
                        </option>
                    @endforeach
                @endforeach
            </select>
            @if ($boskitProducts->isEmpty())
                <p class="text-xs text-amber-600 mt-1">Belum ada produk Boskit. Tambahkan di halaman Produk (nama/tipe mengandung "boskit").</p>
            @endif
        </div>

        {{-- Boskit 2 --}}
        <div id="field-boskit_2" class="kelengkapan-field hidden">
            <label class="label">Boskit 2</label>
            <select name="variant_boskit_2" class="input variant-select">
                <option value="">— pilih boskit kedua —</option>
                @foreach ($boskitProducts as $product)
                    @foreach ($product->variants as $variant)
                        <option value="{{ $variant->id }}"
                                data-price="{{ $product->purchase_price }}"
                                data-selling="{{ $product->selling_price }}">
                            {{ $product->name }} — {{ $variant->name }} ({{ $variant->sku }})
                        </option>
                    @endforeach
                @endforeach
            </select>
        </div>

        {{-- Spoiler --}}
        <div id="field-spoiler" class="kelengkapan-field hidden">
            <label class="label">Spoiler</label>
            <select name="variant_spoiler" class="input variant-select">
                <option value="">— pilih spoiler —</option>
                @foreach ($spoilerProducts as $product)
                    @foreach ($product->variants as $variant)
                        <option value="{{ $variant->id }}"
                                data-price="{{ $product->purchase_price }}"
                                data-selling="{{ $product->selling_price }}">
                            {{ $product->name }} — {{ $variant->name }} ({{ $variant->sku }})
                        </option>
                    @endforeach
                @endforeach
            </select>
            @if ($spoilerProducts->isEmpty())
                <p class="text-xs text-amber-600 mt-1">Belum ada produk Spoiler. Tambahkan di halaman Produk (nama/tipe mengandung "spoiler").</p>
            @endif
        </div>

        {{-- Klakson --}}
        <div id="field-klakson" class="kelengkapan-field hidden">
            <label class="label">Klakson</label>
            <select name="variant_klakson" class="input variant-select">
                <option value="">— pilih klakson —</option>
                @foreach ($klaksonProducts as $product)
                    @foreach ($product->variants as $variant)
                        <option value="{{ $variant->id }}"
                                data-price="{{ $product->purchase_price }}"
                                data-selling="{{ $product->selling_price }}">
                            {{ $product->name }} — {{ $variant->name }} ({{ $variant->sku }})
                        </option>
                    @endforeach
                @endforeach
            </select>
            @if ($klaksonProducts->isEmpty())
                <p class="text-xs text-amber-600 mt-1">Belum ada produk Klakson. Tambahkan di halaman Produk (nama/tipe mengandung "klakson").</p>
            @endif
        </div>
    </div>

    {{-- Harga Jual (Auto) & Harga Modal (Auto) --}}
    <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
            <label class="label">Harga Jual (Auto)</label>
            <input type="text" id="harga-jual-display" class="input bg-gray-100 font-mono" readonly
                   value="" placeholder="Otomatis dihitung">
            <input type="hidden" name="harga_jual" id="harga-jual-value"
                   value="{{ old('harga_jual', 0) }}">
            <p class="text-xs text-gray-500 mt-1">Σ(harga jual variant yang dipilih) × jumlah.</p>
        </div>

        <div>
            <label class="label">Harga Modal (Auto)</label>
            <input type="text" id="harga-modal-display" class="input bg-gray-100 font-mono" readonly
                   value="" placeholder="Otomatis dihitung">
            <input type="hidden" name="harga_modal" id="harga-modal-value"
                   value="{{ old('harga_modal', $existingItems->first()?->harga_modal ?? 0) }}">
            <p class="text-xs text-gray-500 mt-1">Σ(harga beli variant yang dipilih) × jumlah.</p>
        </div>
    </div>
</div>

{{-- JavaScript: dynamic kelengkapan fields & auto-calculate harga modal --}}
<script>
(function () {
    // Mapping kelengkapan code => array of field IDs yang harus tampil.
    // Harus sinkron dengan $kelengkapanFieldMap di PHP & saveOrderItems() controller.
    const KELENGKAPAN_MAP = @json($kelengkapanFieldMap);

    document.addEventListener('DOMContentLoaded', function () {
        const kelengkapanSelect = document.getElementById('kelengkapan-select');
        const allFields = document.querySelectorAll('.kelengkapan-field');
        const hargaModalDisplay = document.getElementById('harga-modal-display');
        const hargaModalValue = document.getElementById('harga-modal-value');
        const hargaJualDisplay = document.getElementById('harga-jual-display');
        const hargaJualValue = document.getElementById('harga-jual-value');
        const itemQuantity = document.getElementById('item-quantity');

        function toggleFields() {
            const val = kelengkapanSelect.value;
            const activeFields = KELENGKAPAN_MAP[val] || [];

            // Hide all first
            allFields.forEach(function (el) {
                el.classList.add('hidden');
            });

            // Show only the ones in the map
            activeFields.forEach(function (fieldKey) {
                const el = document.getElementById('field-' + fieldKey);
                if (el) {
                    el.classList.remove('hidden');
                }
            });

            calculateTotals();
        }

        function formatRp(n) {
            return 'Rp ' + n.toLocaleString('id-ID');
        }

        function calculateTotals() {
            let totalModal = 0;
            let totalJual = 0;
            const qty = parseInt(itemQuantity.value) || 1;

            document.querySelectorAll('.variant-select').forEach(function (sel) {
                const parent = sel.closest('.kelengkapan-field');
                if (parent && !parent.classList.contains('hidden')) {
                    const selected = sel.options[sel.selectedIndex];
                    if (selected) {
                        totalModal += parseFloat(selected.dataset.price) || 0;
                        totalJual  += parseFloat(selected.dataset.selling) || 0;
                    }
                }
            });

            totalModal = totalModal * qty;
            totalJual  = totalJual * qty;

            hargaModalValue.value = totalModal;
            hargaJualValue.value  = totalJual;

            hargaModalDisplay.value = totalModal > 0 ? formatRp(totalModal) : '';
            hargaJualDisplay.value  = totalJual  > 0 ? formatRp(totalJual)  : '';
        }

        kelengkapanSelect.addEventListener('change', toggleFields);
        itemQuantity.addEventListener('input', calculateTotals);
        document.querySelectorAll('.variant-select').forEach(function (sel) {
            sel.addEventListener('change', calculateTotals);
        });

        // Initial state
        toggleFields();
    });
})();
</script>
