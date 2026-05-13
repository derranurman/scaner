{{--
    Partial form untuk create/edit order.
    Expects: $order (null untuk create), $platforms (Collection).
--}}
@php
    $o = $order ?? null;
    $statusOptions = [
        \App\Models\Order::STATUS_PENDING => 'Pending',
        \App\Models\Order::STATUS_PACKED => 'Packed',
        \App\Models\Order::STATUS_CANCELLED => 'Cancelled',
    ];
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
