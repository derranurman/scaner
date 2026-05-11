<?php

namespace App\Services;

use Smalot\PdfParser\Parser;

/**
 * Parser label pengiriman TikTok Shop (kurir J&T).
 *
 * Strategi:
 *  1. Ekstrak teks per halaman pakai smalot/pdfparser.
 *  2. Tiap halaman = 1 pesanan. Pisahkan halaman dengan form feed (\f).
 *  3. Regex + heuristik untuk ambil field:
 *     - Resi    : pola JX / JP + digit, minimal 10 digit
 *     - Order ID: "Order ID: <digit>"  atau "Order Id : <digit>"
 *     - Pembeli : setelah "Penerima :" sampai baris berikutnya
 *     - HP      : pola (+62)... di baris penerima
 *     - Alamat  : 2 baris setelah "Penerima :" (kota + detail)
 *     - Weight  : "Weight : X KG"
 *     - Ship    : "Ship : dd-mm-yyyy"
 *     - Barang  : setelah "Jumlah : Npcs, Barang :" sampai newline
 *     - Product : blok setelah "Product Name ... Qty"
 *     - Seller Note: "Seller Note: <text>"
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
        // Normalize whitespace tapi pertahankan newline
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace("/[ \t]+/", ' ', $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        return trim((string) $text);
    }

    /**
     * @return array<string, mixed>
     */
    public function parseSinglePage(string $text, int $pageNumber = 1): array
    {
        $lines = array_values(array_filter(
            array_map('trim', explode("\n", $text)),
            fn ($l) => $l !== ''
        ));

        $fields = $this->walkLines($lines);

        return [
            'page' => $pageNumber,
            'raw_text' => $text,
            'resi_number' => $this->extractResi($text),
            'tiktok_order_id' => $this->extractOrderId($text),
            'courier' => $this->extractCourier($text),
            'buyer_name' => $fields['buyer_name'],
            'buyer_phone' => $fields['buyer_phone'],
            'shipping_address' => $fields['shipping_address'],
            'weight' => $fields['weight'],
            'order_date' => $fields['order_date'],
            'barang_keyword' => $fields['barang_keyword'],
            'sender_name' => $fields['sender_name'],
            'product_rows' => $this->extractProductRows($text),
            'seller_note' => $this->extractSellerNote($text),
        ];
    }

    /**
     * Berjalan baris-per-baris, mengisi field ketika anchor ditemukan.
     * Ini lebih tahan banting dibanding regex multiline.
     *
     * @param array<int, string> $lines
     * @return array<string, ?string>
     */
    private function walkLines(array $lines): array
    {
        $buyerName = null;
        $buyerPhone = null;
        $senderName = null;
        $addressParts = [];
        $weight = null;
        $orderDate = null;
        $barangKeyword = null;

        $mode = null; // 'address' aktif setelah "Penerima :" sampai ketemu Weight/Jumlah/dll

        foreach ($lines as $line) {
            // --- Pengirim: nama + HP pengirim
            if (preg_match('/^Pengirim\s*[:\-]\s*(.*)$/i', $line, $m)) {
                $rest = trim($m[1]);
                // Buang HP kalau ada di baris yang sama
                if (preg_match('/(\(\+?62\)[\d\*\-\s]{5,30})/', $rest, $mm)) {
                    $senderName = trim(str_replace($mm[0], '', $rest));
                } else {
                    $senderName = $rest ?: null;
                }
                if ($senderName === '') {
                    $senderName = null;
                }
                continue;
            }

            // --- Penerima: nama + HP (bisa di satu baris)
            if (preg_match('/^Penerima\s*[:\-]\s*(.*)$/i', $line, $m)) {
                $rest = trim($m[1]);
                if (preg_match('/(\(\+?62\)[\d\*\-\s]{5,30})/', $rest, $mm)) {
                    $buyerPhone = trim($mm[1]);
                    $buyerName = trim(str_replace($mm[0], '', $rest));
                } else {
                    $buyerName = $rest ?: null;
                }
                if ($buyerName === '') {
                    $buyerName = null;
                }
                $mode = 'address';
                continue;
            }

            // --- Weight [+ Ship di baris yang sama]
            if (preg_match('/Weight\s*[:\-]\s*([\d\.,]+\s*(?:KG|kg|g))/i', $line, $m)) {
                $weight = trim($m[1]);
                if (preg_match('/Ship\s*[:\-]\s*(\d{1,2}[-\/]\d{1,2}[-\/]\d{2,4})/i', $line, $m2)) {
                    $orderDate = $this->normalizeDate($m2[1]);
                }
                $mode = null;
                continue;
            }

            if (preg_match('/^Ship\s*[:\-]\s*(\d{1,2}[-\/]\d{1,2}[-\/]\d{2,4})/i', $line, $m)) {
                $orderDate = $this->normalizeDate($m[1]);
                $mode = null;
                continue;
            }

            // --- Jumlah : Npcs, Barang : <KEYWORD>
            if (preg_match('/Barang\s*[:\-]\s*(.+)$/i', $line, $m)) {
                $barangKeyword = trim($m[1]);
                $mode = null;
                continue;
            }

            // --- Anchor-anchor lain yang meng-cancel mode "address"
            if (preg_match('/^(Pengirim|Weight|Ship|Jumlah|JL\s*\.|Order\s*Id|In transit|Product\s*Name|Qty\s*Total|Seller\s*Note|Syarat\s+dan\s+ketentuan)\b/i', $line)) {
                $mode = null;
                continue;
            }

            // --- Baris alamat (antara Penerima dan Weight/Jumlah/dll)
            if ($mode === 'address') {
                if (preg_match('/^J[XP]\d{8,}$/', $line)) {
                    continue; // nomor resi
                }
                if (preg_match('/^\d{3}-[A-Z0-9]{2,}-\d+[A-Z]?$/i', $line)) {
                    continue; // kode sortir
                }
                if (preg_match('/^\d{1,4}$/', $line)) {
                    continue; // angka kode
                }

                $addressParts[] = $line;
                if (count($addressParts) >= 3) {
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
        // Pola: JX/JP + 10-16 digit. Pilih yang paling sering muncul (biasanya di beberapa sisi label).
        if (preg_match_all('/\b(J[XP]\d{10,16})\b/i', $text, $matches)) {
            $counts = array_count_values(array_map('strtoupper', $matches[1]));
            arsort($counts);

            return array_key_first($counts);
        }

        // Fallback: 12-16 digit yang mengikuti teks umum
        if (preg_match('/(?:Resi|No\.?\s*Resi)\s*[:\-]?\s*([A-Z0-9]{10,20})/i', $text, $m)) {
            return strtoupper(trim($m[1]));
        }

        return null;
    }

    private function extractOrderId(string $text): ?string
    {
        if (preg_match('/Order\s*Id\s*[:\-]?\s*(\d{10,24})/i', $text, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    private function extractCourier(string $text): string
    {
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
     * Extract rows dari blok tabel "Product Name | SKU | Seller SKU | Qty".
     *
     * Format biasanya (satu baris bisa wrap):
     *   Stir Racing import R13" Universal    Sparco     Stir+Bosskit    1
     *                                        Hitam,
     *
     * Karena tabelnya bisa multi-line per row, kita:
     *  1. Cari anchor "Product Name" sampai "Qty Total" / "Order ID"
     *  2. Setiap row berakhir dengan angka Qty di ujung baris
     *
     * @return array<int, array<string, mixed>>
     */
    private function extractProductRows(string $text): array
    {
        if (! preg_match(
            '/Product\s*Name\s+SKU\s+Seller\s*SKU\s+Qty\s*(.+?)(?:Qty\s*Total|Order\s*ID\s*[:\-]|Seller\s*Note|$)/is',
            $text,
            $m
        )) {
            return [];
        }

        $block = trim($m[1]);
        // Row berakhir saat ketemu angka di akhir baris
        $lines = array_values(array_filter(array_map('trim', explode("\n", $block))));

        $rows = [];
        $buffer = [];
        foreach ($lines as $line) {
            $buffer[] = $line;
            if (preg_match('/\s(\d{1,4})$/', $line, $matchQty)) {
                $joined = implode(' ', $buffer);
                $qty = (int) $matchQty[1];

                // Sisa setelah buang qty di akhir
                $rest = trim(preg_replace('/\s\d{1,4}$/', '', $joined));

                // Split by 2+ whitespace untuk kolom
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

    private function extractSellerNote(string $text): ?string
    {
        if (preg_match('/Seller\s*Note\s*[:\-]\s*([^\n]+)/i', $text, $m)) {
            return trim($m[1]);
        }

        return null;
    }
}
