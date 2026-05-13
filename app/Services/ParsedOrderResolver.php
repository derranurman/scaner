<?php

namespace App\Services;

use App\Models\ComboMapping;
use App\Models\Product;
use App\Models\Variant;

/**
 * Ubah hasil parse PDF menjadi daftar item yang siap dibuat OrderItem,
 * dengan resolusi ke Variant lewat:
 *  1. Combo Mapping (berdasarkan teks "Barang :" atau seller_sku)
 *  2. Per-row:
 *     a. Nama produk  — cari master yang name-nya substring dari teks label
 *        (label_name + seller_sku). Master "Skeleton Import R14" akan cocok
 *        dengan label "Stir Racing New Skeleton Import R14 Black".
 *     b. SKU          — exact match di Variant.sku atau Product.sku
 *     c. Seller Note  — cari master yang name-nya substring dari seller note
 *
 * Untuk memilih varian saat produk sudah ketemu, dipakai kamus warna
 * EN↔ID (Black↔Hitam dll) supaya "Black" di label bisa match varian
 * "Hitam" di master.
 *
 * Output item:
 *   [
 *     'product_name' => string,
 *     'variant_name' => ?string,
 *     'sku' => ?string,
 *     'variant_id' => ?int,
 *     'quantity' => int,
 *     'source' => 'combo' | 'sku' | 'name' | 'seller_note' | 'unmatched',
 *     'matched_keyword' => ?string,
 *   ]
 */
class ParsedOrderResolver
{
    /**
     * Kamus warna EN↔ID. Dipakai dua arah saat matching varian.
     *
     * @var array<string, array<int, string>>
     */
    private const COLOR_SYNONYMS = [
        'black'  => ['hitam'],
        'white'  => ['putih'],
        'red'    => ['merah'],
        'blue'   => ['biru'],
        'green'  => ['hijau'],
        'yellow' => ['kuning'],
        'grey'   => ['abu', 'abu-abu', 'gray'],
        'gray'   => ['abu', 'abu-abu', 'grey'],
        'brown'  => ['coklat', 'cokelat'],
        'orange' => ['oranye', 'jingga'],
        'pink'   => ['merah muda', 'merahmuda'],
        'purple' => ['ungu'],
        'silver' => ['silver', 'perak'],
        'gold'   => ['emas'],
        'cream'  => ['krem'],
        'navy'   => ['dongker', 'navy'],
        'maroon' => ['marun'],
        'hitam'  => ['black'],
        'putih'  => ['white'],
        'merah'  => ['red'],
        'biru'   => ['blue'],
        'hijau'  => ['green'],
        'kuning' => ['yellow'],
        'abu'    => ['grey', 'gray'],
        'coklat' => ['brown'],
        'cokelat'=> ['brown'],
        'ungu'   => ['purple'],
        'perak'  => ['silver'],
        'emas'   => ['gold'],
    ];

    /**
     * @param array<string, mixed> $parsed
     * @return array<string, mixed> { items, warnings, matched_keyword }
     */
    public function resolve(array $parsed): array
    {
        $items = [];
        $warnings = [];
        $matchedKeyword = null;

        // ---- Strategi 1: cari combo mapping yang cocok dengan teks "Barang :"
        //      atau seller_sku / product row. Mapping keyword = substring match.
        $candidates = array_filter([
            $parsed['barang_keyword'] ?? null,
            ...array_map(fn ($r) => $r['seller_sku'] ?? null, $parsed['product_rows'] ?? []),
            ...array_map(fn ($r) => $r['sku'] ?? null, $parsed['product_rows'] ?? []),
        ]);

        $combo = null;
        foreach ($candidates as $cand) {
            $combo = $this->findCombo((string) $cand);
            if ($combo) {
                $matchedKeyword = $combo->keyword;
                break;
            }
        }

        if ($combo) {
            // Qty pesanan dari label (biasanya 1 pcs = 1 set combo)
            $orderQty = $this->totalQtyFromRows($parsed['product_rows'] ?? []);
            if ($orderQty <= 0) {
                $orderQty = 1;
            }

            foreach ($combo->items as $ci) {
                $v = $ci->variant;
                if (! $v) {
                    $warnings[] = "Combo '{$combo->keyword}' memiliki item yang referensinya sudah terhapus.";
                    continue;
                }
                $items[] = [
                    'product_name' => $v->product?->name ?? '—',
                    'variant_name' => $v->name,
                    'sku' => $v->sku,
                    'variant_id' => $v->id,
                    'quantity' => $ci->quantity * $orderQty,
                    'source' => 'combo',
                    'matched_keyword' => $combo->keyword,
                ];
            }
        }

        // ---- Strategi 1b: Seller Note juga dicek sebagai combo mapping tambahan.
        //      Match SEMUA keyword yang cocok (bukan hanya yang pertama),
        //      supaya keyword pendek seperti "T2" bisa ketangkap di samping
        //      keyword panjang seperti "+Bosskit Ferio".
        //      Contoh:
        //        Seller Note: "+Bosskit Ferio (T2)"
        //        Keyword di DB: "+Bosskit Ferio", "T2"
        //        Match: kedua-duanya → stok Boskit Ferio + stok varian T2 dikurangi
        $sellerNote = trim((string) ($parsed['seller_note'] ?? ''));
        if ($sellerNote !== '') {
            $alreadyUsedComboIds = $combo ? [$combo->id] : [];
            $noteCombos = $this->findAllCombos($sellerNote, $alreadyUsedComboIds);

            if (! empty($noteCombos)) {
                $orderQty = $this->totalQtyFromRows($parsed['product_rows'] ?? []);
                if ($orderQty <= 0) {
                    $orderQty = 1;
                }

                foreach ($noteCombos as $noteCombo) {
                    foreach ($noteCombo->items as $ci) {
                        $v = $ci->variant;
                        if (! $v) {
                            $warnings[] = "Combo '{$noteCombo->keyword}' (dari Seller Note) memiliki item yang referensinya sudah terhapus.";
                            continue;
                        }
                        $items[] = [
                            'product_name' => $v->product?->name ?? '—',
                            'variant_name' => $v->name,
                            'sku' => $v->sku,
                            'variant_id' => $v->id,
                            'quantity' => $ci->quantity * $orderQty,
                            'source' => 'combo',
                            'matched_keyword' => $noteCombo->keyword.' (Seller Note)',
                        ];
                    }

                    if (! $matchedKeyword) {
                        $matchedKeyword = $noteCombo->keyword.' (Seller Note)';
                    } else {
                        $matchedKeyword .= ' + '.$noteCombo->keyword.' (Note)';
                    }
                }

                // Merge items yang variant_id-nya sama (biar tidak double-potong stok)
                $items = $this->mergeItemsByVariant($items);
            }
        }

        // Kalau sudah ada items dari combo (barang + seller note), langsung return
        if (! empty($items)) {
            return compact('items', 'warnings', 'matchedKeyword');
        }

        // ---- Strategi 2: tiap baris product di label
        $sellerNote = trim((string) ($parsed['seller_note'] ?? ''));
        foreach ($parsed['product_rows'] ?? [] as $row) {
            $resolved = $this->resolveRow($row, $sellerNote);
            $items[] = $resolved;
            if ($resolved['source'] === 'unmatched') {
                $warnings[] = "Baris produk '{$resolved['product_name']}' belum cocok dengan master. Tambahkan Combo Mapping atau padankan SKU.";
            }
        }

        // Kalau tidak ada product_rows sama sekali, coba fallback ke barang_keyword
        if (empty($items) && ! empty($parsed['barang_keyword'])) {
            $items[] = [
                'product_name' => $parsed['barang_keyword'],
                'variant_name' => null,
                'sku' => null,
                'variant_id' => null,
                'quantity' => 1,
                'source' => 'unmatched',
                'matched_keyword' => null,
            ];
            $warnings[] = "Tidak menemukan tabel produk. Disimpan sebagai '{$parsed['barang_keyword']}'. Buat combo mapping untuk ini.";
        }

        return compact('items', 'warnings', 'matchedKeyword');
    }

    private function totalQtyFromRows(array $rows): int
    {
        $total = 0;
        foreach ($rows as $r) {
            $total += (int) ($r['quantity'] ?? 0);
        }

        return $total;
    }

    /**
     * Resolve satu baris produk ke Variant master.
     *
     * Urutan strategi (stop di yang pertama match):
     *   1. NAMA  — cari Product yang namanya substring dari label text
     *              (label_name + seller_sku). Lalu pilih varian yang name-nya
     *              cocok di label text juga (dengan kamus warna EN↔ID).
     *   2. SKU   — exact match di Variant.sku, lalu Product.sku
     *   3. NOTE  — cari Product yang namanya substring dari seller note
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function resolveRow(array $row, string $sellerNote = ''): array
    {
        $qty = max(1, (int) ($row['quantity'] ?? 1));
        $name = trim((string) ($row['product_name'] ?? ''));
        $sku = trim((string) ($row['sku'] ?? ''));
        $sellerSku = trim((string) ($row['seller_sku'] ?? ''));

        // Teks pencarian untuk strategi nama: nama + variasi (seller_sku)
        // supaya "Black" dari kolom variasi ikut jadi sinyal pemilihan varian.
        $labelText = trim($name.' '.$sellerSku);

        // ---- 1. Match via NAMA produk ----
        $byName = $this->matchByProductName($labelText);
        if ($byName !== null) {
            return $this->makeItem($byName, $name, $qty, 'name');
        }

        // ---- 2. Match via SKU (exact) ----
        foreach ([$sku, $sellerSku] as $candidate) {
            if ($candidate === '') {
                continue;
            }
            // 2a. Variant.sku
            $v = Variant::where('sku', $candidate)->first();
            if ($v) {
                return $this->makeItem($v, $name, $qty, 'sku');
            }
            // 2b. Product.sku → ambil varian pertama / yang cocok warnanya
            $product = Product::where('sku', $candidate)->first();
            if ($product) {
                $variant = $this->pickVariantForLabel($product, $labelText);
                if ($variant) {
                    return $this->makeItem($variant, $name, $qty, 'sku');
                }
            }
        }

        // ---- 3. Match via SELLER NOTE ----
        if ($sellerNote !== '') {
            $byNote = $this->matchByProductName($sellerNote);
            if ($byNote !== null) {
                return $this->makeItem($byNote, $name, $qty, 'seller_note');
            }
        }

        // Fallback: unmatched
        return [
            'product_name' => $name ?: '—',
            'variant_name' => $sellerSku ?: ($sku ?: null),
            'sku' => $sku ?: null,
            'variant_id' => null,
            'quantity' => $qty,
            'source' => 'unmatched',
            'matched_keyword' => null,
        ];
    }

    /**
     * Cari Variant berdasarkan nama produk master yang menjadi substring dari
     * $labelText. Priority terpanjang menang (supaya "Skeleton Import R14"
     * menang atas "Skeleton Import").
     *
     * Untuk produk yang ketemu, pilih varian yang paling cocok dengan
     * $labelText (match warna EN↔ID + nama varian biasa).
     */
    private function matchByProductName(string $labelText): ?Variant
    {
        $labelText = trim($labelText);
        if ($labelText === '' || mb_strlen($labelText) < 3) {
            return null;
        }

        $labelLower = mb_strtolower($labelText);

        // Hanya produk aktif. Urutkan nama terpanjang dulu (more specific wins).
        $products = Product::with('variants')
            ->where('is_active', true)
            ->get()
            ->sortByDesc(fn ($p) => mb_strlen((string) $p->name));

        foreach ($products as $product) {
            $pname = trim((string) $product->name);
            if ($pname === '' || mb_strlen($pname) < 3) {
                continue;
            }

            if (! str_contains($labelLower, mb_strtolower($pname))) {
                continue;
            }

            // Produk cocok. Pilih varian yang paling match dengan label.
            $variant = $this->pickVariantForLabel($product, $labelText);
            if ($variant) {
                return $variant;
            }
        }

        return null;
    }

    /**
     * Pilih varian dari $product yang namanya paling cocok dengan $labelText.
     * Pakai kamus warna EN↔ID supaya "Black" match varian "Hitam".
     * Kalau tidak ada yang match, kembalikan varian pertama (supaya produk
     * yang hanya punya 1 varian tetap ketangkap).
     */
    private function pickVariantForLabel(Product $product, string $labelText): ?Variant
    {
        $variants = $product->variants;
        if ($variants->isEmpty()) {
            return null;
        }

        $labelLower = mb_strtolower($labelText);

        // Skor tiap varian: jumlah token varian yang ketemu di label.
        //   - Exact substring match = 2 poin
        //   - Synonym match (Black↔Hitam) = 2 poin
        $best = null;
        $bestScore = 0;

        foreach ($variants as $v) {
            $vname = trim((string) $v->name);
            if ($vname === '') {
                continue;
            }

            $score = 0;

            // 1. Exact substring nama varian
            $vLower = mb_strtolower($vname);
            if ($vLower !== '' && str_contains($labelLower, $vLower)) {
                $score += 2;
            }

            // 2. Split nama varian jadi token, tiap token dicek di label
            //    (dengan synonym). Contoh varian "Hitam Doff" → cek "Hitam" dan
            //    "Doff" satu-satu.
            $tokens = preg_split('/[\s,\/\-_]+/u', $vLower) ?: [];
            foreach ($tokens as $token) {
                $token = trim($token);
                if (mb_strlen($token) < 2) {
                    continue;
                }
                if (str_contains($labelLower, $token)) {
                    $score += 1;
                    continue;
                }
                // Cek synonym (warna EN↔ID)
                foreach ($this->synonymsFor($token) as $syn) {
                    if (str_contains($labelLower, mb_strtolower($syn))) {
                        $score += 2;
                        break;
                    }
                }
            }

            // 3. Match via SKU varian di label text
            if ($v->sku && str_contains($labelLower, mb_strtolower($v->sku))) {
                $score += 3;
            }

            if ($score > $bestScore) {
                $best = $v;
                $bestScore = $score;
            }
        }

        // Kalau tidak ada yang match dan produk hanya punya 1 varian, pakai itu.
        if ($best === null && $variants->count() === 1) {
            return $variants->first();
        }

        return $best;
    }

    /**
     * Kembalikan daftar sinonim untuk sebuah token warna (case-insensitive).
     *
     * @return array<int, string>
     */
    private function synonymsFor(string $token): array
    {
        $key = mb_strtolower($token);

        return self::COLOR_SYNONYMS[$key] ?? [];
    }

    /**
     * Susun item output dari Variant yang ketemu.
     *
     * @return array<string, mixed>
     */
    private function makeItem(Variant $v, string $fallbackName, int $qty, string $source): array
    {
        return [
            'product_name' => $v->product?->name ?? ($fallbackName ?: '—'),
            'variant_name' => $v->name,
            'sku' => $v->sku,
            'variant_id' => $v->id,
            'quantity' => $qty,
            'source' => $source,
            'matched_keyword' => null,
        ];
    }

    private function findCombo(string $candidate): ?ComboMapping
    {
        $candidate = trim($candidate);
        if ($candidate === '') {
            return null;
        }

        // Urutkan keyword terpanjang lebih dulu (biar mapping lebih spesifik menang)
        $mappings = ComboMapping::with('items.variant.product')->get();
        $mappings = $mappings->sortByDesc(fn ($m) => mb_strlen($m->keyword));

        foreach ($mappings as $mapping) {
            if (mb_stripos($candidate, $mapping->keyword) !== false) {
                return $mapping;
            }
            // Juga coba kebalikan (kalau candidate lebih pendek)
            if (mb_stripos($mapping->keyword, $candidate) !== false) {
                return $mapping;
            }
        }

        return null;
    }

    /**
     * Cari SEMUA combo mapping yang keyword-nya cocok dengan $candidate.
     * Berguna untuk seller note yang bisa mengandung banyak keyword sekaligus.
     *
     * @param array<int, int> $excludeIds ID mapping yang mau diskip
     * @return array<int, ComboMapping>
     */
    private function findAllCombos(string $candidate, array $excludeIds = []): array
    {
        $candidate = trim($candidate);
        if ($candidate === '') {
            return [];
        }

        $mappings = ComboMapping::with('items.variant.product')->get();
        $matches = [];

        foreach ($mappings as $mapping) {
            if (in_array($mapping->id, $excludeIds, true)) {
                continue;
            }

            $keyword = trim($mapping->keyword);
            if ($keyword === '') {
                continue;
            }

            if ($this->keywordMatches($candidate, $keyword)) {
                $matches[] = $mapping;
            }
        }

        return $matches;
    }

    /**
     * Cek keyword cocok di dalam candidate. Untuk keyword pendek (<=3 char),
     * pakai word-boundary supaya keyword "T2" tidak false-match "T23" atau "T20".
     */
    private function keywordMatches(string $candidate, string $keyword): bool
    {
        // Keyword panjang (>=4 char): substring case-insensitive biasa.
        if (mb_strlen($keyword) >= 4) {
            return mb_stripos($candidate, $keyword) !== false
                || mb_stripos($keyword, $candidate) !== false;
        }

        // Keyword pendek: harus berdiri di word-boundary.
        //   - huruf/digit sebelum-sesudah keyword harus bukan alphanumeric
        //   - contoh: "T2" cocok di "Ferio (T2)" tapi TIDAK cocok di "T23"
        $pattern = '/(?<![A-Za-z0-9])'.preg_quote($keyword, '/').'(?![A-Za-z0-9])/iu';

        return preg_match($pattern, $candidate) === 1;
    }

    /**
     * Gabung item dengan variant_id yang sama supaya stok tidak double-potong.
     * Kalau 2 combo mapping nunjuk ke varian yang sama, qty-nya dijumlah dan
     * matched_keyword-nya digabung.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function mergeItemsByVariant(array $items): array
    {
        $merged = [];

        foreach ($items as $item) {
            $key = $item['variant_id'] ?? spl_object_hash((object) $item);

            if (! isset($merged[$key])) {
                $merged[$key] = $item;
                continue;
            }

            $merged[$key]['quantity'] += $item['quantity'];

            $existingKeyword = $merged[$key]['matched_keyword'] ?? '';
            $newKeyword = $item['matched_keyword'] ?? '';
            if ($newKeyword && ! str_contains((string) $existingKeyword, $newKeyword)) {
                $merged[$key]['matched_keyword'] = trim($existingKeyword.' + '.$newKeyword, ' +');
            }
        }

        return array_values($merged);
    }
}
