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
        return [
            'page' => $pageNumber,
            'raw_text' => $text,
            'resi_number' => $this->extractResi($text),
            'tiktok_order_id' => $this->extractOrderId($text),
            'courier' => $this->extractCourier($text),
            'buyer_name' => $this->extractBuyerName($text),
            'buyer_phone' => $this->extractBuyerPhone($text),
            'shipping_address' => $this->extractAddress($text),
            'weight' => $this->extractWeight($text),
            'order_date' => $this->extractShipDate($text),
            'barang_keyword' => $this->extractBarangKeyword($text),
            'product_rows' => $this->extractProductRows($text),
            'seller_note' => $this->extractSellerNote($text),
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

    private function extractBuyerName(string $text): ?string
    {
        // "Penerima : <Nama>   (+62)..."
        if (preg_match('/Penerima\s*[:\-]\s*([^\n(]+?)\s*\(\+?62/i', $text, $m)) {
            return trim($m[1]);
        }

        // Tanpa nomor HP di baris yang sama
        if (preg_match('/Penerima\s*[:\-]\s*([^\n]+)/i', $text, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    private function extractBuyerPhone(string $text): ?string
    {
        if (preg_match('/Penerima[^\n]*?(\(\+?62\)[\d\*\-\s]+)/i', $text, $m)) {
            return trim($m[1]);
        }
        // Kadang HP ada di baris berikutnya
        if (preg_match('/(\(\+?62\)[\d\*\-\s]{6,20})/i', $text, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    private function extractAddress(string $text): ?string
    {
        // Ambil 2 baris setelah "Penerima : ..." (baris provinsi/kota, lalu detail jalan)
        if (! preg_match('/Penerima\s*[:\-][^\n]*\n([^\n]+)\n([^\n]+)/i', $text, $m)) {
            return null;
        }

        $line1 = trim($m[1]);   // JAWA TIMUR,MALANG,KEDUNGKANDANG
        $line2 = trim($m[2]);   // jl.jengkol bumiayu no 16 ,pagar hitam

        // Buang garis "Weight" / kode sortir JL . XXXX (dengan dot/spasi), pertahankan "jl.xxx" alamat
        if (preg_match('/^(Weight|Berat|Ship)\b/i', $line2)
            || preg_match('/^JL\s*\.\s*[A-Z]/', $line2)) {
            return $line1;
        }

        return trim($line1.' — '.$line2, ' —');
    }

    private function extractWeight(string $text): ?string
    {
        if (preg_match('/Weight\s*[:\-]\s*([\d\.,]+\s*(?:KG|kg|g))/i', $text, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    private function extractShipDate(string $text): ?string
    {
        if (preg_match('/Ship\s*[:\-]\s*(\d{1,2}[-\/]\d{1,2}[-\/]\d{2,4})/i', $text, $m)) {
            return $this->normalizeDate($m[1]);
        }

        return null;
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
     * Teks di baris "Jumlah : Npcs, Barang : <KEYWORD>"
     * Ini yang dipakai sebagai kunci combo mapping.
     */
    private function extractBarangKeyword(string $text): ?string
    {
        if (preg_match('/Barang\s*[:\-]\s*([^\n]+)/i', $text, $m)) {
            return trim($m[1]);
        }

        return null;
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
