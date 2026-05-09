@extends('layouts.app')
@section('title', 'Scan Resi')

@section('content')
    @php($header = 'Scan Resi — Packing')
    @php($subheader = 'Scan barcode resi JNT dengan kamera HP atau scanner USB.')

    <div x-data="scanApp()" x-init="init()" class="grid grid-cols-1 lg:grid-cols-5 gap-6">
        {{-- Panel kiri: scanner + input --}}
        <div class="card lg:col-span-2">
            <div class="flex items-center justify-between mb-3">
                <div class="text-xs text-gray-500">
                    Pending: <b class="text-amber-600">{{ $pendingCount }}</b>
                    · Saya hari ini: <b class="text-indigo-600">{{ $mePackedToday }}</b>
                </div>
                <button type="button" @click="toggleCamera()"
                        class="btn-secondary text-xs"
                        x-text="cameraOn ? 'Matikan Kamera' : 'Aktifkan Kamera'"></button>
            </div>

            <div x-show="cameraOn" class="mb-3 rounded-lg overflow-hidden border border-gray-200 aspect-video bg-black">
                <div id="qr-reader" class="w-full h-full"></div>
            </div>

            <form @submit.prevent="doLookup()" class="space-y-3">
                <div>
                    <label class="label">Nomor Resi</label>
                    <input x-ref="resiInput" x-model="resi" inputmode="search" autocomplete="off"
                           placeholder="Scan / ketik resi lalu Enter" required
                           class="input text-lg font-mono tracking-wide">
                </div>
                <div class="flex gap-2">
                    <button class="btn-primary flex-1" type="submit" :disabled="loading">
                        <span x-show="!loading">Cari Pesanan</span>
                        <span x-show="loading">Memuat…</span>
                    </button>
                    <button type="button" class="btn-secondary" @click="reset()">Reset</button>
                </div>
            </form>

            <div x-show="message" class="mt-3 rounded-lg px-3 py-2 text-sm"
                 :class="messageType === 'error' ? 'bg-red-50 text-red-700 border border-red-200'
                       : messageType === 'warning' ? 'bg-amber-50 text-amber-800 border border-amber-200'
                       : 'bg-green-50 text-green-700 border border-green-200'"
                 x-text="message"></div>
        </div>

        {{-- Panel kanan: detail pesanan --}}
        <div class="card lg:col-span-3">
            <template x-if="!order">
                <div class="text-center py-16 text-gray-400">
                    <svg class="mx-auto h-12 w-12 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 6h16M4 12h16M4 18h16"/>
                    </svg>
                    <p class="text-sm">Scan resi atau masukkan nomor resi untuk melihat pesanan.</p>
                </div>
            </template>

            <template x-if="order">
                <div>
                    <div class="flex items-start justify-between mb-4">
                        <div>
                            <div class="text-xs uppercase text-gray-500">Resi</div>
                            <div class="text-2xl font-mono font-bold" x-text="order.resi_number"></div>
                            <div class="text-xs text-gray-500 mt-1">
                                Pembeli: <span x-text="order.buyer_name || '—'"></span>
                            </div>
                        </div>
                        <span class="badge"
                              :class="order.status === 'pending' ? 'bg-amber-100 text-amber-700'
                                    : order.status === 'packed' ? 'bg-green-100 text-green-700'
                                    : 'bg-gray-100 text-gray-600'"
                              x-text="order.status"></span>
                    </div>

                    <h3 class="font-semibold mb-2">Kelengkapan Item</h3>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="text-left text-xs uppercase text-gray-500 border-b">
                                <tr>
                                    <th class="py-2">Produk</th>
                                    <th class="py-2">Varian</th>
                                    <th class="py-2">SKU</th>
                                    <th class="py-2 text-right">Qty</th>
                                    <th class="py-2 text-right">Stok</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <template x-for="item in order.items" :key="item.sku + item.product_name">
                                    <tr :class="!item.has_variant || (item.stock ?? 0) < item.quantity ? 'bg-red-50' : ''">
                                        <td class="py-2" x-text="item.product_name"></td>
                                        <td class="py-2" x-text="item.variant_name || '—'"></td>
                                        <td class="py-2 font-mono text-xs">
                                            <span x-text="item.sku || '—'"></span>
                                            <span x-show="!item.has_variant" class="badge bg-red-100 text-red-700 ml-1">Tidak terdaftar</span>
                                        </td>
                                        <td class="py-2 text-right font-semibold text-lg" x-text="item.quantity"></td>
                                        <td class="py-2 text-right" x-text="item.stock ?? '—'"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>

                    <template x-if="warnings && warnings.length">
                        <div class="mt-3 bg-amber-50 border border-amber-200 rounded-lg p-3 text-xs text-amber-800">
                            <div class="font-semibold mb-1">Peringatan:</div>
                            <ul class="list-disc list-inside space-y-0.5">
                                <template x-for="w in warnings" :key="w"><li x-text="w"></li></template>
                            </ul>
                        </div>
                    </template>

                    <div class="mt-5 pt-4 border-t flex gap-2">
                        <button class="btn-primary flex-1 text-base py-3"
                                @click="doConfirm()"
                                :disabled="!canConfirm || loading">
                            <span x-show="!loading">Konfirmasi Packed & Kurangi Stok</span>
                            <span x-show="loading">Memproses…</span>
                        </button>
                        <button class="btn-secondary" @click="reset()">Scan Lagi</button>
                    </div>

                    <template x-if="order.status === 'packed'">
                        <div class="mt-3 text-xs text-gray-500">
                            Dipacking oleh <b x-text="order.packed_by"></b> pada <span x-text="order.packed_at"></span>.
                        </div>
                    </template>
                </div>
            </template>
        </div>
    </div>

    <audio id="beep-ok" preload="auto">
        <source src="data:audio/wav;base64,UklGRmgAAABXQVZFZm10IBAAAAABAAEAIlYAAESsAAACABAAZGF0YQAAAAA=" type="audio/wav">
    </audio>

    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <script>
        function scanApp() {
            return {
                resi: '',
                loading: false,
                order: null,
                warnings: [],
                message: '',
                messageType: 'success',
                cameraOn: false,
                qr: null,

                init() {
                    // Autofocus input supaya USB scanner (keyboard wedge) bisa langsung ketik
                    this.$nextTick(() => this.$refs.resiInput?.focus());
                    // USB scanner biasanya diakhiri Enter → form submit otomatis
                },

                async toggleCamera() {
                    if (this.cameraOn) {
                        try { await this.qr?.stop(); } catch(e) {}
                        this.cameraOn = false;
                        return;
                    }
                    this.cameraOn = true;
                    await this.$nextTick();
                    try {
                        this.qr = new Html5Qrcode("qr-reader");
                        await this.qr.start(
                            { facingMode: "environment" },
                            { fps: 10, qrbox: { width: 280, height: 140 } },
                            (decoded) => {
                                this.resi = decoded.trim();
                                this.doLookup();
                                try { this.qr.pause(true); } catch(e) {}
                                setTimeout(() => { try { this.qr.resume(); } catch(e){} }, 1500);
                            },
                            () => {}
                        );
                    } catch (e) {
                        this.cameraOn = false;
                        this.showMsg('Tidak bisa mengakses kamera: ' + e.message, 'error');
                    }
                },

                get canConfirm() {
                    if (!this.order || this.order.status !== 'pending') return false;
                    return this.order.items.every(it => it.has_variant && (it.stock ?? 0) >= it.quantity);
                },

                async doLookup() {
                    if (!this.resi.trim()) return;
                    this.loading = true;
                    this.message = '';
                    this.order = null;
                    this.warnings = [];
                    try {
                        const res = await fetch('{{ route('scan.lookup') }}', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                            },
                            body: JSON.stringify({ resi_number: this.resi.trim() })
                        });
                        const data = await res.json();
                        if (!res.ok) {
                            this.showMsg(data.message || 'Gagal memuat pesanan.', data.code === 'already_packed' ? 'warning' : 'error');
                            if (data.order) this.order = data.order;
                            return;
                        }
                        this.order = data.order;
                        this.warnings = data.warnings || [];
                        if (this.warnings.length) {
                            this.showMsg('Pesanan ditemukan, tapi ada peringatan.', 'warning');
                        } else {
                            this.showMsg('Pesanan ditemukan. Cek kelengkapan lalu klik "Konfirmasi Packed".', 'success');
                        }
                    } catch (e) {
                        this.showMsg('Kesalahan jaringan: ' + e.message, 'error');
                    } finally {
                        this.loading = false;
                        this.$refs.resiInput?.select();
                    }
                },

                async doConfirm() {
                    if (!this.order) return;
                    this.loading = true;
                    try {
                        const res = await fetch('{{ route('scan.confirm') }}', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                            },
                            body: JSON.stringify({ resi_number: this.order.resi_number })
                        });
                        const data = await res.json();
                        if (!res.ok) {
                            this.showMsg(data.message || 'Gagal mengkonfirmasi.', 'error');
                            return;
                        }
                        this.order = data.order;
                        this.showMsg(data.message, 'success');
                    } catch (e) {
                        this.showMsg('Kesalahan: ' + e.message, 'error');
                    } finally {
                        this.loading = false;
                    }
                },

                reset() {
                    this.resi = '';
                    this.order = null;
                    this.warnings = [];
                    this.message = '';
                    this.$refs.resiInput?.focus();
                },

                showMsg(msg, type = 'success') {
                    this.message = msg;
                    this.messageType = type;
                },
            };
        }
    </script>
@endsection
