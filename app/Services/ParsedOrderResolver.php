<?php

namespace App\Services;

use App\Models\ComboMapping;
use App\Models\Variant;

/**
 * Ubah hasil parse PDF menjadi daftar item yang siap dibuat OrderItem,
 * dengan resolusi ke Variant lewat:
 *  1. Combo Mapping (berdasarkan teks "Barang :" atau seller_sku)
 *  2. Exact match SKU
 *  3. Case-insensitive like match untuk nama produk + varian
 *
 * Output item:
 *   [
 *     'product_name' => string,
 *     'variant_name' => ?string,
 *     'sku' => ?string,
 *     'variant_id' => ?int,
 *     'quantity' => int,
 *     'source' => 'combo' | 'sku' | 'name' | 'unmatched',
 *     'matched_keyword' => ?string,
 *   ]
 */
class ParsedOrderResolver
{
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
        foreach ($parsed['product_rows'] ?? [] as $row) {
            $resolved = $this->resolveRow($row);
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
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function resolveRow(array $row): array
    {
        $qty = max(1, (int) ($row['quantity'] ?? 1));
        $name = $row['product_name'] ?? '';
        $sku = $row['sku'] ?? null;
        $sellerSku = $row['seller_sku'] ?? null;

        // 2a. Exact SKU
        foreach ([$sku, $sellerSku] as $candidate) {
            if ($candidate) {
                $v = Variant::where('sku', $candidate)->first();
                if ($v) {
                    return [
                        'product_name' => $v->product?->name ?? $name,
                        'variant_name' => $v->name,
                        'sku' => $v->sku,
                        'variant_id' => $v->id,
                        'quantity' => $qty,
                        'source' => 'sku',
                        'matched_keyword' => null,
                    ];
                }
            }
        }

        // 2b. Name + variant match (coba SKU teks yang muncul sebagai nama varian)
        if ($name) {
            $variantHint = $sku ?: $sellerSku;
            $v = Variant::whereHas('product', fn ($q) => $q->where('name', 'like', "%{$name}%"))
                ->when($variantHint, fn ($q) => $q->where('name', 'like', "%{$variantHint}%"))
                ->first();
            if ($v) {
                return [
                    'product_name' => $v->product?->name ?? $name,
                    'variant_name' => $v->name,
                    'sku' => $v->sku,
                    'variant_id' => $v->id,
                    'quantity' => $qty,
                    'source' => 'name',
                    'matched_keyword' => null,
                ];
            }
        }

        return [
            'product_name' => $name ?: '—',
            'variant_name' => $sku ?: $sellerSku,
            'sku' => $sku,
            'variant_id' => null,
            'quantity' => $qty,
            'source' => 'unmatched',
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
