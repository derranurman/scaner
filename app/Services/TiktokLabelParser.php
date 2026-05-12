<?php

namespace App\Services;

use Smalot\PdfParser\Parser;

/**
 * Parser label pengiriman marketplace.
 *
 * Didukung:
 *  - TikTok Shop + J&T Express (resi JX/JP + kolom Weight/Ship/Order Id)
 *  - Shopee + SPX (Shopee Express) (resi SPXID + kolom Berat/Batas Kirim/No.Pesanan)
 *
 * Strategi: auto-detect format dari header teks, lalu walkLines()
 * dengan anchor keyword spesifik marketplace.
 */
class TiktokLabelParser
{
    /**
     * @return array<int, array<string, mixed>> Satu entry per halaman (per pesanan)
     */
    public function parseFile(string $pdfPath): array
    {
        $parser = new Parser();
        $pdf = $parser->parseFile($pdfPath);

        $orders = [];
        $pages = $pdf->getPages();

        foreach ($pages as $index => $page) {
            $text = $this->cleanText($page->getText());
            if (trim($text) === '') {
                continue;
            }

            $parsed = $this->parseSinglePage($text, $index + 1);
            if ($parsed['resi_number']) {
                $orders[] = $parsed;
            }
        }

        return $orders;
    }

    private function cleanText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace("/[ \t]+/", ' ', $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        return trim((string) $text);
    }

    /**
     * Deteksi marketplace: 'shopee' kalau ada 'SPXID' / 'Shopee' / 'SPX',
     * selain itu default 'tiktok'.
     */
    private function detectMarketplace(string $text): string
    {
        if (preg_match('/\bSPXID\d+|\bShopee\b|\bSPX\b|Shop\s*Express/i', $text)) {
            return 'shopee';
        }

        return 'tiktok';
    }

    /**
     * @return array<string, mixed>
     */
    public function parseSinglePage(string $text, int $pageNumber = 1): array
    {
        $marketplace = $this->detectMarketplace($text);

        $lines = array_values(array_filter(
            array_map('trim', explode("\n", $text)),
            fn ($l) => $l !== ''
        ));

        $fields = $this->walkLines($lines, $marketplace);

        return [
            'page' => $pageNumber,
            'marketplace' => $marketplace,
            'raw_text' => $text,
            'resi_number' => $this->extractResi($text),
            'tiktok_order_id' => $this->extractOrderId($text, $marketplace),
            'courier' => $this->extractCourier($text, $marketplace),
            'buyer_name' => $fields['buyer_name'],
            'buyer_phone' => $fields['buyer_phone'],
            'shipping_address' => $fields['shipping_address'],
            'weight' => $fields['weight'],
            'order_date' => $fields['order_date'],
            'barang_keyword' => $fields['barang_keyword'],
            'sender_name' => $fields['sender_name'],
            'product_rows' => $this->extractProductRows($text, $marketplace),
            'seller_note' => $this->extractSellerNote($text, $marketplace),
        ];
    }

    /**
     * Walk baris-per-baris, mengisi field ketika anchor ditemukan.
     *
     * @param array<int, string> $lines
     * @return array<string, ?string>
     */
    private function walkLines(array $lines, string $marketplace): array
    {
        $buyerName = null;
        $buyerPhone = null;
        $senderName = null;
        $addressParts = [];
        $weight = null;
        $orderDate = null;
        $barangKeyword = null;

        $mode = null; // 'address' aktif setelah "Penerima" sampai ketemu Weight/Berat/Jumlah/dll

        $breakAnchors = $marketplace === 'shopee'
            ? '/^(Berat|Batas\s*Kirim|No\.?\s*Pesanan|COD|Nama\s*Produk|Pesan|Order\s*ID|#\s*Nama)\b/i'
            : '/^(Pengirim|Weight|Ship|Jumlah|JL\s*\.|Order\s*Id|In transit|Product\s*Name|Qty\s*Total|Seller\s*Note|Syarat\s+dan\s+ketentuan)\b/i';

        foreach ($lines as $line) {
            // --- Shopee sering punya "Penerima: X Pengirim: Y" di satu baris.
            //     Tangani keduanya sekaligus kalau ketemu pola ini.
            if ($marketplace === 'shopee'
                && preg_match('/^Penerima\s*[:\-]\s*(.*?)\s+Pengirim\s*[:\-]\s*(.+)$/i', $line, $m)) {
                $buyerName = trim($m[1]) ?: null;
                $senderName = trim($m[2]) ?: null;
                $mode = 'address';
                continue;
            }

            // --- Pengirim: nama + HP
            if (preg_match('/^Pengirim\s*[:\-]\s*(.*)$/i', $line, $m)) {
                $rest = trim($m[1]);
                if (preg_match('/(\(\+?62\)[\d\*\-\s]{5,30})/', $rest, $mm)) {
                    $senderName = trim(str_replace($mm[0], '', $rest)) ?: null;
                } elseif (preg_match('/(\b\d{10,15}\b)/', $rest, $mm)) {
                    $senderName = trim(str_replace($mm[0], '', $rest)) ?: null;
                } else {
                    $senderName = $rest ?: null;
                }
                continue;
            }

            // --- Penerima: nama + HP
            if (preg_match('/^Penerima\s*[:\-]\s*(.*)$/i', $line, $m)) {
                $rest = trim($m[1]);
                if (preg_match('/(\(\+?62\)[\d\*\-\s]{5,30})/', $rest, $mm)) {
                    $buyerPhone = trim($mm[1]);
                    $buyerName = trim(str_replace($mm[0], '', $rest)) ?: null;
                } elseif (preg_match('/(\b\d{10,15}\b)/', $rest, $mm)) {
                    $buyerPhone = trim($mm[1]);
                    $buyerName = trim(str_replace($mm[0], '', $rest)) ?: null;
                } else {
                    $buyerName = $rest ?: null;
                }
                $mode = 'address';
                continue;
            }

            // --- Weight / Berat (+ optional Ship/Batas Kirim di baris yang sama)
            if (preg_match('/(?:Weight|Berat)\s*[:\-]\s*([\d\.,]+\s*(?:KG|kg|gr|g))/i', $line, $m)) {
                $weight = trim($m[1]);
                if (preg_match('/(?:Ship|Batas\s*Kirim)\s*[:\-]\s*(\d{1,2}[-\/]\d{1,2}[-\/]\d{2,4})/i', $line, $m2)) {
                    $orderDate = $this->normalizeDate($m2[1]);
                }
                $mode = null;
                continue;
            }

            // --- Ship / Batas Kirim standalone
            if (preg_match('/^(?:Ship|Batas\s*Kirim)\s*[:\-]\s*(\d{1,2}[-\/]\d{1,2}[-\/]\d{2,4})/i', $line, $m)) {
                $orderDate = $this->normalizeDate($m[1]);
                $mode = null;
                continue;
            }

            // --- Jumlah : Npcs, Barang : <KEYWORD>  (TikTok only)
            if ($marketplace === 'tiktok' && preg_match('/Barang\s*[:\-]\s*(.+)$/i', $line, $m)) {
                $barangKeyword = trim($m[1]);
                $mode = null;
                continue;
            }

            // --- Anchor yang meng-cancel mode "address"
            if (preg_match($breakAnchors, $line)) {
                $mode = null;
                continue;
            }

            // --- Baris alamat
            if ($mode === 'address') {
                // Skip HP yang muncul solo di bawah "Penerima: Nama"
                //   Kalau belum ada buyer_phone & baris ini pure digit 10-15, anggap HP.
                if (! $buyerPhone && preg_match('/^\d{10,15}$/', $line)) {
                    $buyerPhone = $line;
                    continue;
                }

                if (preg_match('/^(J[XP]\d{8,}|SPXID\d{8,})$/i', $line)) {
                    continue; // nomor resi
                }
                if (preg_match('/^\d{3}-[A-Z0-9]{2,}-\d+[A-Z]?$/i', $line)) {
                    continue; // kode sortir TikTok
                }
                if (preg_match('/^LOP[- ]?[A-Z]?[- ]?\d+$/i', $line)) {
                    continue; // kode sortir Shopee (LOP-C-06)
                }
                if (preg_match('/^\d{1,4}$/', $line)) {
                    continue; // angka kode
                }
                if (preg_match('/^V\s*[-]\s*\d+/', $line)) {
                    continue; // "V - 2" (nomor handle Shopee)
                }
                if (preg_match('/^COD$/i', $line)) {
                    continue;
                }

                $addressParts[] = $line;
                if (count($addressParts) >= 4) {
                    $mode = null;
                }
                continue;
            }
        }

        return [
            'buyer_name' => $buyerName,
            'buyer_phone' => $buyerPhone,
            'sender_name' => $senderName,
            'shipping_address' => $addressParts ? implode(', ', $addressParts) : null,
            'weight' => $weight,
            'order_date' => $orderDate,
            'barang_keyword' => $barangKeyword,
        ];
    }

    private function extractResi(string $text): ?string
    {
        // Shopee: SPXID + 10+ digit
        if (preg_match_all('/\b(SPXID\d{10,20})\b/i', $text, $matches)) {
            $counts = array_count_values(array_map('strtoupper', $matches[1]));
            arsort($counts);

            return array_key_first($counts);
        }

        // TikTok/J&T: JX/JP + 10-16 digit
        if (preg_match_all('/\b(J[XP]\d{10,16})\b/i', $text, $matches)) {
            $counts = array_count_values(array_map('strtoupper', $matches[1]));
            arsort($counts);

            return array_key_first($counts);
        }

        // Fallback generik
        if (preg_match('/(?:Resi|No\.?\s*Resi)\s*[:\-]?\s*([A-Z0-9]{10,24})/i', $text, $m)) {
            return strtoupper(trim($m[1]));
        }

        return null;
    }

    private function extractOrderId(string $text, string $marketplace): ?string
    {
        if ($marketplace === 'shopee') {
            if (preg_match('/No\.?\s*Pesanan\s*[:\-]?\s*([A-Z0-9]{8,24})/i', $text, $m)) {
                return strtoupper(trim($m[1]));
            }
        }

        // TikTok: "Order Id : 58394..." (digit saja)
        if (preg_match('/Order\s*Id\s*[:\-]?\s*(\d{10,24})/i', $text, $m)) {
            return trim($m[1]);
        }

        // Fallback Order ID umum
        if (preg_match('/Order\s*ID\s*[:\-]?\s*([A-Z0-9]{8,24})/i', $text, $m)) {
            return strtoupper(trim($m[1]));
        }

        return null;
    }

    private function extractCourier(string $text, string $marketplace): string
    {
        if ($marketplace === 'shopee') {
            return 'SPX';
        }
        if (preg_match('/J&T\s*Express|J\s*&\s*T/i', $text)) {
            return 'JNT';
        }
        if (preg_match('/JNE\b/i', $text)) {
            return 'JNE';
        }
        if (preg_match('/SiCepat/i', $text)) {
            return 'SiCepat';
        }
        if (preg_match('/Anteraja/i', $text)) {
            return 'Anteraja';
        }

        return 'JNT';
    }

    private function normalizeDate(string $raw): ?string
    {
        $raw = str_replace('/', '-', trim($raw));
        $parts = explode('-', $raw);
        if (count($parts) !== 3) {
            return null;
        }
        [$d, $m, $y] = $parts;
        if (strlen($y) === 2) {
            $y = '20'.$y;
        }

        return sprintf('%04d-%02d-%02d', (int) $y, (int) $m, (int) $d);
    }

    /**
     * Extract rows dari tabel produk.
     *
     * @return array<int, array<string, mixed>>
     */
    private function extractProductRows(string $text, string $marketplace): array
    {
        if ($marketplace === 'shopee') {
            return $this->extractShopeeProductRows($text);
        }

        return $this->extractTiktokProductRows($text);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractTiktokProductRows(string $text): array
    {
        if (! preg_match(
            '/Product\s*Name\s+SKU\s+Seller\s*SKU\s+Qty\s*(.+?)(?:Qty\s*Total|Order\s*ID\s*[:\-]|Seller\s*Note|$)/is',
            $text,
            $m
        )) {
            return [];
        }

        $block = trim($m[1]);
        $lines = array_values(array_filter(array_map('trim', explode("\n", $block))));

        $rows = [];
        $buffer = [];
        foreach ($lines as $line) {
            $buffer[] = $line;
            if (preg_match('/\s(\d{1,4})$/', $line, $matchQty)) {
                $joined = implode(' ', $buffer);
                $qty = (int) $matchQty[1];

                $rest = trim(preg_replace('/\s\d{1,4}$/', '', $joined));
                $cols = preg_split('/\s{2,}/', $rest) ?: [];

                $rows[] = [
                    'product_name' => $cols[0] ?? $rest,
                    'sku' => $cols[1] ?? null,
                    'seller_sku' => $cols[2] ?? null,
                    'quantity' => $qty,
                    'raw_line' => $rest,
                ];
                $buffer = [];
            }
        }

        return $rows;
    }

    /**
     * Shopee: "# Nama Produk SKU Variasi Qty"
     *
     * Contoh:
     *   "1 Stir kayu Palang Aluminium semi Celong Ring 15 &14 inc R14 Silver,Dus+buble 1"
     *
     * Karena di PDF Shopee banyak yang cuma 1 spasi antar kolom, kita pakai
     * heuristik berbeda: scan dari kanan (Qty), lalu Variasi (mengandung koma
     * atau simbol+), lalu SKU (alfanumerik pendek), sisanya nama produk.
     *
     * @return array<int, array<string, mixed>>
     */
    private function extractShopeeProductRows(string $text): array
    {
        if (! preg_match(
            '/#\s*Nama\s*Produk\s+SKU\s+Variasi\s+Qty\s*(.+?)(?:Pesan\s*[:\(]|Order\s*ID\s*[:\-]|SPXID\d+|$)/is',
            $text,
            $m
        )) {
            return [];
        }

        $block = trim($m[1]);
        $lines = array_values(array_filter(array_map('trim', explode("\n", $block))));

        $rows = [];
        $buffer = [];

        foreach ($lines as $line) {
            $buffer[] = $line;

            // Row berakhir saat ada angka qty di akhir DAN row diawali nomor urut (1, 2, ...)
            if (! preg_match('/\s(\d{1,4})\s*$/', $line, $matchQty)) {
                continue;
            }

            $joined = implode(' ', $buffer);
            if (! preg_match('/^\d+\s+/', $joined)) {
                continue;
            }

            $qty = (int) $matchQty[1];
            $rest = trim(preg_replace('/\s\d{1,4}\s*$/', '', $joined));
            $rest = preg_replace('/^\d+\s+/', '', $rest); // buang nomor urut di depan

            // Strategi 1: Split by 2+ whitespace (kalau PDF pakai banyak spasi)
            $cols = preg_split('/\s{2,}/', $rest) ?: [];

            if (count($cols) >= 3) {
                $productName = $cols[0];
                $sku = $cols[1];
                $variation = $cols[2];
            } else {
                // Strategi 2: Scan dari kanan.
                //   Variasi biasanya mengandung koma atau "+" (mis. "Silver,Dus+buble")
                //   SKU biasanya alfanumerik pendek tanpa spasi (mis. "R14", "STIR-SPR-BLK")
                [$productName, $sku, $variation] = $this->splitShopeeRowFromRight($rest);
            }

            $rows[] = [
                'product_name' => trim($productName ?? $rest),
                'sku' => $sku ? trim($sku) : null,
                'seller_sku' => $variation ? trim($variation) : null,
                'quantity' => $qty,
                'raw_line' => $rest,
            ];
            $buffer = [];
        }

        return $rows;
    }

    /**
     * Pisah "Nama Produk  SKU  Variasi" dari kanan berdasarkan pola.
     *
     * @return array{0: ?string, 1: ?string, 2: ?string}
     */
    private function splitShopeeRowFromRight(string $rest): array
    {
        $tokens = preg_split('/\s+/', $rest) ?: [];
        if (count($tokens) < 2) {
            return [$rest, null, null];
        }

        // Variasi = token terakhir kalau mengandung koma/plus/slash, atau
        //           1-2 token terakhir yang bukan kata umum.
        //   Kita ambil maks 2 token terakhir sebagai kandidat variasi.
        $variation = array_pop($tokens);

        // Cek kalau token variasi sangat pendek (<4 char) & token sebelumnya
        // juga "variasi-like", gabungkan.
        //   (Kasus wrap seperti "Silver,\nDus+buble")
        if (! empty($tokens)) {
            $prev = end($tokens);
            if (str_contains($variation, ',') === false
                && (str_contains($prev, ',') || str_ends_with($prev, ','))) {
                $variation = array_pop($tokens).' '.$variation;
            }
        }

        // SKU = token sebelum variasi (biasanya 2-10 karakter alfanumerik, tanpa spasi)
        $sku = null;
        if (! empty($tokens)) {
            $maybeSku = end($tokens);
            if (preg_match('/^[A-Z0-9][A-Z0-9\-_]{1,14}$/i', $maybeSku)) {
                array_pop($tokens);
                $sku = $maybeSku;
            }
        }

        $productName = implode(' ', $tokens);

        return [$productName ?: null, $sku, $variation];
    }

    /**
     * Seller Note / Pesan.
     */
    private function extractSellerNote(string $text, string $marketplace): ?string
    {
        if ($marketplace === 'shopee') {
            if (preg_match('/Pesan\s*[:\-]\s*([^\n]+)/i', $text, $m)) {
                $note = trim($m[1]);
                // Skip kalau cuma "(orderid)" doang
                if (preg_match('/^\([A-Z0-9]+\)\s*$/', $note)) {
                    return null;
                }
                return $note;
            }
        }

        if (preg_match('/Seller\s*Note\s*[:\-]\s*([^\n]+)/i', $text, $m)) {
            return trim($m[1]);
        }

        return null;
    }
}
