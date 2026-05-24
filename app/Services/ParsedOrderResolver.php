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
            return $this->makeItem($byName['variant'], $name, $qty, 'name', $byName['reason']);
        }

        // ---- 2. Match via SKU (exact) ----
        foreach ([$sku, $sellerSku] as $candidate) {
            if ($candidate === '') {
                continue;
            }
            // 2a. Variant.sku
            $v = Variant::where('sku', $candidate)->first();
            if ($v) {
                return $this->makeItem($v, $name, $qty, 'sku', "SKU: {$candidate}");
            }
            // 2b. Product.sku → ambil varian pertama / yang cocok warnanya
            $product = Product::where('sku', $candidate)->first();
            if ($product) {
                $pick = $this->pickVariantForLabel($product, $labelText);
                if ($pick !== null) {
                    return $this->makeItem(
                        $pick['variant'],
                        $name,
                        $qty,
                        'sku',
                        "SKU: {$candidate}".($pick['reason'] ? ' · '.$pick['reason'] : '')
                    );
                }
            }
        }

        // ---- 3. Match via SELLER NOTE ----
        if ($sellerNote !== '') {
            $byNote = $this->matchByProductName($sellerNote);
            if ($byNote !== null) {
                return $this->makeItem(
                    $byNote['variant'],
                    $name,
                    $qty,
                    'seller_note',
                    'Note: '.$byNote['reason']
                );
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
     *
     * @return array{variant: Variant, reason: string}|null
     */
    private function matchByProductName(string $labelText): ?array
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
            $pick = $this->pickVariantForLabel($product, $labelText);
            if ($pick !== null) {
                return [
                    'variant' => $pick['variant'],
                    'reason' => "Master: {$pname}".($pick['reason'] ? ' · '.$pick['reason'] : ''),
                ];
            }
        }

        return null;
    }

    /**
     * Pilih varian dari $product yang namanya paling cocok dengan $labelText.
     *
     * Scoring pakai WORD-BOUNDARY match supaya "merah" tidak asal substring
     * dengan "racing" atau "semi merah-merahan" dst:
     *   - Nama varian exact (word-boundary) di label: 10 poin
     *   - Token varian (split by space/slash/dash) ketemu word-boundary: 5 poin
     *   - Synonym warna (Black↔Hitam) ketemu word-boundary: 10 poin
     *
     * SKU varian TIDAK dihitung di sini — ada strategi SKU match terpisah di
     * resolver. Ini supaya SKU yang kebetulan punya token umum ("R14") tidak
     * bikin varian "salah" menang atas varian yang warnanya benar-benar cocok.
     *
     * Kalau produk hanya punya 1 varian, varian itu dipakai tanpa scoring
     * (banyak produk master yang tidak punya pilihan varian).
     *
     * @return array{variant: Variant, reason: string}|null
     */
    private function pickVariantForLabel(Product $product, string $labelText): ?array
    {
        $variants = $product->variants;
        if ($variants->isEmpty()) {
            return null;
        }

        // Produk dengan 1 varian saja: langsung pilih (apapun nama varian-nya).
        if ($variants->count() === 1) {
            return [
                'variant' => $variants->first(),
                'reason' => '',
            ];
        }

        $labelLower = mb_strtolower($labelText);

        $best = null;
        $bestScore = 0;
        $bestReason = '';

        foreach ($variants as $v) {
            $vname = trim((string) $v->name);
            if ($vname === '') {
                continue;
            }

            $score = 0;
            $reasonParts = [];

            // 1. Nama varian penuh (word-boundary).
            if ($this->hasWord($labelLower, $vname)) {
                $score += 10;
                $reasonParts[] = $vname;
            }

            // 2. Tiap token nama varian (word-boundary).
            $tokens = preg_split('/[\s,\/\-_]+/u', mb_strtolower($vname)) ?: [];
            foreach ($tokens as $token) {
                $token = trim($token);
                if (mb_strlen($token) < 2) {
                    continue;
                }
                if ($this->hasWord($labelLower, $token)) {
                    $score += 5;
                    continue;
                }
                // 3. Synonym warna (EN↔ID)
                foreach ($this->synonymsFor($token) as $syn) {
                    if ($this->hasWord($labelLower, $syn)) {
                        $score += 10;
                        $reasonParts[] = "{$syn}→{$v->name}";
                        break;
                    }
                }
            }

            if ($score > $bestScore) {
                $best = $v;
                $bestScore = $score;
                $bestReason = $reasonParts ? implode(', ', array_unique($reasonParts)) : '';
            }
        }

        if ($best === null) {
            return null;
        }

        return [
            'variant' => $best,
            'reason' => $bestReason,
        ];
    }

    /**
     * Cek apakah $needle muncul sebagai kata (word-boundary) di $haystack.
     * Case-insensitive. $haystack diasumsikan sudah lowercase.
     *
     * Word-boundary di sini: karakter sebelum & sesudah harus bukan huruf/digit.
     * Contoh: hasWord("stir racing new skeleton import r14\" black", "black")
     *         → TRUE (preceded by space, followed by end)
     *         hasWord("stir racing new skeleton", "red") → FALSE
     */
    private function hasWord(string $haystack, string $needle): bool
    {
        $needle = mb_strtolower(trim($needle));
        if ($needle === '') {
            return false;
        }

        $pattern = '/(?<![A-Za-z0-9])'.preg_quote($needle, '/').'(?![A-Za-z0-9])/iu';

        return preg_match($pattern, $haystack) === 1;
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
    private function makeItem(Variant $v, string $fallbackName, int $qty, string $source, ?string $reason = null): array
    {
        return [
            'product_name' => $v->product?->name ?? ($fallbackName ?: '—'),
            'variant_name' => $v->name,
            'sku' => $v->sku,
            'variant_id' => $v->id,
            'quantity' => $qty,
            'source' => $source,
            'matched_keyword' => $reason ?: null,
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
            // Forward: keyword muncul di candidate (paling natural).
            //   keyword = "Stir Racing"
            //   candidate barang_keyword = "Stir Racing Mugen R13.5" → match
            if (mb_stripos($candidate, $mapping->keyword) !== false) {
                return $mapping;
            }

            // Reverse: candidate muncul di keyword. Ini dipakai kalau user
            // simpan keyword PANJANG yang termasuk varian (mis.
            //   "SPOILER MOBIL...UNIVERSAL — Default")
            // sementara candidate dari PDF hanya bagian produknya saja
            // (mis. "SPOILER MOBIL...UNIVERSAL").
            //
            // Tapi reverse-match HARUS substansial. Tanpa guard, candidate
            // sependek "Default" atau "hitam" akan nge-trigger keyword
            // panjang manapun yang punya substring tsb di ekor → cross-
            // contamination antar produk. Karena itu kita wajibkan candidate:
            //   - minimal 8 karakter, DAN
            //   - minimal 50% dari panjang keyword.
            if ($this->isSubstantialReverseMatch($candidate, $mapping->keyword)) {
                return $mapping;
            }
        }

        return null;
    }

    /**
     * Cek apakah $candidate cukup substansial untuk dipertimbangkan sebagai
     * reverse-substring match terhadap $keyword. Mencegah candidate pendek
     * (mis. nama warna / "Default") meng-trigger keyword panjang manapun
     * yang kebetulan punya substring tsb.
     */
    private function isSubstantialReverseMatch(string $candidate, string $keyword): bool
    {
        $candLen = mb_strlen($candidate);
        $kwLen = mb_strlen($keyword);

        if ($candLen < 8) {
            return false;
        }
        if ($kwLen === 0 || $candLen / $kwLen < 0.5) {
            return false;
        }

        return mb_stripos($keyword, $candidate) !== false;
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
        // Keyword panjang (>=4 char): substring case-insensitive.
        if (mb_strlen($keyword) >= 4) {
            // Forward: keyword muncul di candidate.
            if (mb_stripos($candidate, $keyword) !== false) {
                return true;
            }
            // Reverse: candidate muncul di keyword. Sama seperti findCombo,
            // candidate harus substansial supaya keyword panjang tidak
            // ke-trigger oleh fragment pendek.
            return $this->isSubstantialReverseMatch($candidate, $keyword);
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
