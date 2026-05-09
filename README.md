# Scaner Toko — MVP

Sistem manajemen stok & scan resi untuk penjual TikTok Shop.

**Alur utama:**

1. Admin import pesanan dari TikTok Shop (CSV) → web
2. User Packing scan nomor resi JNT (kamera HP / scanner USB)
3. Sistem menampilkan daftar item pesanan → packer cek kelengkapan
4. Klik **Konfirmasi Packed** → stok otomatis berkurang
5. Admin lihat laporan: siapa scan apa, berapa banyak, kapan

---

## Fitur

- Auth + 2 role: **admin** dan **packing**
- CRUD **Produk** + **Varian** (multi-varian: warna, ukuran, dll)
- **Import CSV** pesanan dari TikTok Shop (template tersedia)
- **Scan resi** pakai kamera HP (html5-qrcode) atau scanner USB (keyboard wedge)
- **Auto-kurangi stok** saat resi dikonfirmasi packed (transaksi atomic + lockForUpdate)
- **Stock movement log** (setiap mutasi stok dicatat)
- **Laporan Packing** per user, per rentang tanggal, detail item + export CSV
- Dashboard: total stok, pending order, stok menipis

---

## Stack

- Laravel 11 (PHP 8.2+)
- SQLite (lokal) — tinggal ganti `DB_CONNECTION=mysql` di `.env` untuk produksi
- Blade + Tailwind + Alpine.js + Livewire
- [html5-qrcode](https://github.com/mebjas/html5-qrcode) untuk scan via kamera

---

## Instalasi Lokal

Prasyarat: **PHP 8.2+**, **Composer**, **Node.js 18+**.

```bash
# 1. Clone
git clone https://github.com/derranurman/scaner.git
cd scaner

# 2. Install dependencies
composer install
npm install

# 3. Setup environment
cp .env.example .env
php artisan key:generate

# 4. Buat DB SQLite & migrate + seed
touch database/database.sqlite
php artisan migrate --seed

# 5. Build asset + jalankan
npm run dev         # terminal 1 (Vite)
php artisan serve   # terminal 2 (http://localhost:8000)
```

### Akun Demo

| Role    | Email                 | Password   |
| ------- | --------------------- | ---------- |
| Admin   | `admin@toko.test`     | `password` |
| Packing | `packing@toko.test`   | `password` |
| Packing | `packing2@toko.test`  | `password` |

---

## Cara Pakai

### 1. Import Pesanan TikTok

1. Dari TikTok Seller Center, **Export** pesanan ke file CSV / Excel.
2. Buka `Import CSV` di web → download **Template CSV** sebagai referensi kolom.
3. Sesuaikan kolom CSV Anda dengan template:
   ```
   tiktok_order_id, resi_number, courier, buyer_name, buyer_phone,
   shipping_address, order_date, product_name, variant_name, sku, quantity
   ```
   **Kolom wajib:** `resi_number`, `quantity`. `sku` sangat dianjurkan agar stok bisa terkurangi otomatis.
4. Upload. Satu resi dengan banyak baris akan digabung menjadi satu pesanan dengan banyak item.

### 2. Scan Resi (Packing)

- Login sebagai user packing → halaman `Scan` langsung terbuka.
- **Pakai scanner USB:** cukup fokus ke input, scan → Enter otomatis.
- **Pakai kamera HP:** klik **Aktifkan Kamera**, arahkan ke barcode JNT.
- Sistem tampilkan daftar item → cek kelengkapan fisik → klik **Konfirmasi Packed**.
- Stok otomatis berkurang, resi ditandai `packed`, tercatat di laporan.

### 3. Laporan

- Admin → `Laporan` → pilih rentang tanggal & user.
- Lihat ringkasan (total pesanan, total pcs per user) + detail scan tiap resi + daftar item.
- Klik **Export CSV** untuk download laporan detail item.

---

## Struktur Data

```
users          : id, name, email, role(admin|packing), is_active
products       : id, name, sku, is_active
variants       : id, product_id, name, sku, stock, min_stock
orders         : id, tiktok_order_id, resi_number (unik), buyer_*,
                 status(pending|packed|cancelled), packed_at, packed_by_user_id
order_items    : id, order_id, variant_id (nullable), product_name,
                 variant_name, sku, quantity      ← snapshot nama saat import
packing_logs   : id, user_id, order_id, resi_number, total_items,
                 distinct_skus, scanned_at
stock_movements: id, variant_id, user_id, order_id, type(in|out|adjustment),
                 qty, stock_after, reference
```

### Kenapa nama produk di-snapshot di `order_items`?

Supaya kalau produk master di-rename / dihapus, histori pesanan & laporan tetap menunjukkan nama yang benar pada saat order dibuat.

---

## Skema Scan (Algoritma)

```
POST /scan/lookup  { resi_number }
  → Cari Order by resi_number
  → Validasi: ada? status pending? stok cukup untuk semua item?
  → Balas JSON { order, items, warnings }

POST /scan/confirm { resi_number }
  → DB::transaction {
      lockForUpdate Order
      Loop items: stockService.adjust(variant, -qty, 'out', user, order, "Scan <resi>")
                  → lockForUpdate Variant, update stock, insert stock_movement
      Order.status = packed, packed_at = now, packed_by = user
      Insert packing_log
    }
  → Balas JSON { order, message }
```

---

## Roadmap (setelah lokal)

- Hosting (shared / VPS). Ganti `DB_CONNECTION=mysql`, jalankan `php artisan migrate --seed`.
- [ ] Integrasi langsung TikTok Shop Partner API (opsional, setelah akses developer disetujui)
- [ ] Print label resi dari web
- [ ] Notifikasi stok menipis via WhatsApp
- [ ] Multi-toko

---

## Kontak

Dibangun dengan Kiro.
