<?php

namespace App\Services;

use Smalot\PdfParser\Parser;

/**
 * Parser label pengiriman marketplace.
 *
 * Didukung:
 *  - TikTok Shop + J&T Express (resi JX/JP + kolom Weight/Ship/Order Id)
 *  - TikTok Shop / Tokopedia + J&T Cargo (FastTrack) — sama tabel produk,
 *    tapi resi & courier berbeda (J&T CARGO branding)
 *  - Shopee + SPX (Shopee Express) (resi SPXID + kolom Berat/Batas Kirim/No.Pesanan)
 *
 * Strategi: auto-detect format dari header teks, lalu walkLines()
 * dengan anchor keyword spesifik marketplace.
 *
 * Multi-page handling:
 *  - Tiap PDF page diparse independen.
 *  - Halaman tanpa resi tapi punya Order ID dianggap "continuation page"
 *    (mis. halaman ke-2 J&T Express yang berisi Customer Message / Seller Note)
 *    dan di-merge ke primary page dengan Order ID yang sama.
 *  - Halaman dengan resi sama akan di-dedupe (ambil yang paling lengkap,
 *    merge seller_note dari sisanya).
 */
class TiktokLabelParser
{
    /**
     * @return array<int, array<string, mixed>> Satu entry per pesanan
     */
    public function parseFile(string $pdfPath): array
    {
        $parser = new Parser();
        $pdf = $parser->parseFile($pdfPath);

        $allPages = [];
        $pages = $pdf->getPages();

        foreach ($pages as $index => $page) {
            $text = $this->cleanText($page->getText());
            if (trim($text) === '') {
                continue;
            }

            $allPages[] = $this->parseSinglePage($text, $index + 1);
        }

        return $this->consolidatePages($allPages);
    }

    /**
     * Gabungkan halaman-halaman PDF menjadi daftar pesanan unik.
     *
     * Logika:
     *  1. Pisahkan halaman primer (punya resi) dan halaman continuation
     *     (tidak punya resi, hanya berisi info tambahan seperti Customer
     *     Message / Seller Note pada layout 2-page J&T Express).
     *  2. Dedupe halaman primer berdasarkan resi: kalau ada >1 halaman
     *     dengan resi yang sama, ambil yang paling lengkap (punya
     *     product_rows / buyer_name) sebagai primary, merge seller_note
     *     dari sisanya.
     *  3. Untuk tiap halaman continuation, cari primary dengan Order ID
     *     yang sama dan merge seller_note + customer_message + raw_text.
     *     Continuation tanpa primary yang cocok di-drop diam-diam.
     *
     * @param  array<int, array<string, mixed>>  $allPages
     * @return array<int, array<string, mixed>>
     */
    private function consolidatePages(array $allPages): array
    {
        $primaryByResi = [];
        $continuations = [];

        foreach ($allPages as $page) {
            if (! empty($page['resi_number'])) {
                $resi = strtoupper((string) $page['resi_number']);
                if (! isset($primaryByResi[$resi])) {
                    $primaryByResi[$resi] = $page;
                } else {
                    // Resi duplikat: pilih halaman yang paling lengkap sebagai
                    // primary, merge field tambahan dari yang lain.
                    $primaryByResi[$resi] = $this->mergePrimaryPages($primaryByResi[$resi], $page);
                }
                continue;
            }

            // Halaman tanpa resi — kandidat continuation.
            // Cuma simpan kalau ada Order ID atau seller_note / customer_message,
            // selain itu drop (halaman noise).
            $hasUseful = ! empty($page['tiktok_order_id'])
                || ! empty($page['seller_note'])
                || ! empty($page['customer_message']);
            if ($hasUseful) {
                $continuations[] = $page;
            }
        }

        // Merge continuation pages ke primary by Order ID.
        $primaryByOrderId = [];
        foreach ($primaryByResi as $resi => $primary) {
            $oid = (string) ($primary['tiktok_order_id'] ?? '');
            if ($oid !== '') {
                $primaryByOrderId[$oid] = &$primaryByResi[$resi];
            }
        }

        foreach ($continuations as $cont) {
            $oid = (string) ($cont['tiktok_order_id'] ?? '');
            if ($oid === '' || ! isset($primaryByOrderId[$oid])) {
                continue;
            }
            $this->mergeContinuationInto($primaryByOrderId[$oid], $cont);
        }
        unset($primaryByOrderId);

        // Output urutkan berdasarkan halaman primer-nya.
        $orders = array_values($primaryByResi);
        usort($orders, fn ($a, $b) => (int) ($a['page'] ?? 0) <=> (int) ($b['page'] ?? 0));

        return $orders;
    }

    /**
     * Gabung 2 halaman primer dengan resi yang sama. Pilih yang paling lengkap
     * sebagai dasar, isi field kosong dari yang lain.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     * @return array<string, mixed>
     */
    private function mergePrimaryPages(array $a, array $b): array
    {
        $scoreA = $this->pageCompletenessScore($a);
        $scoreB = $this->pageCompletenessScore($b);

        [$primary, $other] = $scoreB > $scoreA ? [$b, $a] : [$a, $b];

        foreach (['tiktok_order_id', 'buyer_name', 'buyer_phone', 'sender_name',
                  'shipping_address', 'weight', 'order_date', 'barang_keyword',
                  'courier'] as $key) {
            if (empty($primary[$key]) && ! empty($other[$key])) {
                $primary[$key] = $other[$key];
            }
        }

        if (empty($primary['product_rows']) && ! empty($other['product_rows'])) {
            $primary['product_rows'] = $other['product_rows'];
        }

        // Seller note: gabung kalau berbeda.
        $primary['seller_note'] = $this->joinDistinct($primary['seller_note'] ?? null, $other['seller_note'] ?? null);
        if (! empty($other['customer_message'] ?? null)) {
            $primary['customer_message'] = $this->joinDistinct(
                $primary['customer_message'] ?? null,
                $other['customer_message'] ?? null
            );
        }

        // Raw text di-append (untuk debug)
        if (! empty($other['raw_text'])) {
            $primary['raw_text'] = trim(($primary['raw_text'] ?? '')."\n\n--- halaman ".($other['page'] ?? '?')." ---\n".$other['raw_text']);
        }

        return $primary;
    }

    /**
     * Merge continuation page (tanpa resi) ke primary page by-reference.
     *
     * @param  array<string, mixed>  $primary
     * @param  array<string, mixed>  $cont
     */
    private function mergeContinuationInto(array &$primary, array $cont): void
    {
        $primary['seller_note'] = $this->joinDistinct(
            $primary['seller_note'] ?? null,
            $cont['seller_note'] ?? null
        );
        if (! empty($cont['customer_message'] ?? null)) {
            $primary['customer_message'] = $this->joinDistinct(
                $primary['customer_message'] ?? null,
                $cont['customer_message'] ?? null
            );
        }

        // Append raw_text untuk debugging
        if (! empty($cont['raw_text'])) {
            $primary['raw_text'] = trim(($primary['raw_text'] ?? '')."\n\n--- halaman ".($cont['page'] ?? '?')." (lanjutan) ---\n".$cont['raw_text']);
        }

        // Kalau primary tidak ada seller_note tapi continuation punya
        // customer_message, taruh sebagai seller_note juga supaya combo
        // mapping yang baca seller_note bisa ketangkap.
        if (empty($primary['seller_note']) && ! empty($cont['customer_message'] ?? null)) {
            $primary['seller_note'] = $cont['customer_message'];
        }
    }

    private function pageCompletenessScore(array $p): int
    {
        $score = 0;
        if (! empty($p['product_rows'])) {
            $score += 5;
        }
        if (! empty($p['buyer_name'])) {
            $score += 2;
        }
        if (! empty($p['shipping_address'])) {
            $score += 2;
        }
        if (! empty($p['barang_keyword'])) {
            $score += 1;
        }
        if (! empty($p['seller_note'])) {
            $score += 1;
        }

        return $score;
    }

    private function joinDistinct(?string $a, ?string $b): ?string
    {
        $a = trim((string) $a);
        $b = trim((string) $b);
        if ($a === '' && $b === '') {
            return null;
        }
        if ($a === '') {
            return $b;
        }
        if ($b === '' || mb_stripos($a, $b) !== false) {
            return $a;
        }
        if (mb_stripos($b, $a) !== false) {
            return $b;
        }

        return $a.' | '.$b;
    }

    private function cleanText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace("/[ \t]+/", ' ', $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        return trim((string) $text);
    }

    /**
     * Deteksi marketplace:
     *  - 'shopee'    → ada 'SPXID' / 'Shopee' / 'SPX'
     *  - 'tokopedia' → ada watermark 'tokopedia' (tanpa indikator Shopee)
     *  - 'tiktok'    → default (juga dipakai untuk label TikTok Shop yang
     *                  dikirim via J&T Cargo karena layout tabel produk sama)
     */
    private function detectMarketplace(string $text): string
    {
        if (preg_match('/\bSPXID\d+|\bShopee\b|\bSPX\b|Shop\s*Express/i', $text)) {
            return 'shopee';
        }

        // Tokopedia: hanya kalau ada watermark "tokopedia" tapi BUKAN TikTok Shop
        // (banyak label TikTok Shop juga mencantumkan tokopedia footer).
        $hasTokped = preg_match('/\btokopedia\b/i', $text) === 1;
        $hasTiktok = preg_match('/\btiktok\b|TT\s*Order\s*ID/i', $text) === 1;
        if ($hasTokped && ! $hasTiktok) {
            return 'tokopedia';
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
            'resi_number' => $this->extractResi($text, $marketplace),
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
            'customer_message' => $this->extractCustomerMessage($text),
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
            // --- Shopee sering punya "Penerima: X Pengirim: Y" di satu baris
            //     (atau terbalik: "Pengirim: Y Penerima: X" tergantung cara
            //     PDF extractor membaca layout 2-kolom).
            //     Tangani keduanya sekaligus kalau ketemu pola ini.
            if ($marketplace === 'shopee'
                && preg_match('/^Penerima\s*[:\-]\s*(.*?)\s+Pengirim\s*[:\-]\s*(.+)$/i', $line, $m)) {
                $buyerName = trim($m[1]) ?: null;
                // Strip nomor HP dari sender_name jika muncul setelah nama pengirim
                $senderRaw = trim($m[2]);
                if (preg_match('/^(.+?)\s+(\d{10,15})$/', $senderRaw, $sp)) {
                    $senderName = trim($sp[1]) ?: null;
                } else {
                    $senderName = $senderRaw ?: null;
                }
                $mode = 'address';
                continue;
            }

            // --- Shopee: urutan terbalik "Pengirim: Y Penerima: X" (PDF extractor
            //     membaca kolom kanan duluan pada layout 2-kolom label SPX).
            if ($marketplace === 'shopee'
                && preg_match('/^Pengirim\s*[:\-]\s*(.*?)\s+Penerima\s*[:\-]\s*(.+)$/i', $line, $m)) {
                $senderRaw = trim($m[1]);
                if (preg_match('/^(.+?)\s+(\d{10,15})$/', $senderRaw, $sp)) {
                    $senderName = trim($sp[1]) ?: null;
                } else {
                    $senderName = $senderRaw ?: null;
                }
                $buyerName = trim($m[2]) ?: null;
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

        // --- Shopee fallback: Jika sender_name masih null ATAU buyer_name
        //     sama dengan sender_name (indikasi PDF parser salah assign kolom),
        //     scan ulang teks untuk memperbaiki.
        if ($marketplace === 'shopee') {
            $needFallback = ($senderName === null);
            // Juga trigger fallback jika buyer = sender (salah tangkap)
            if (! $needFallback && $buyerName !== null && $senderName !== null
                && mb_strtolower($buyerName) === mb_strtolower($senderName)) {
                $needFallback = true;
            }

            if ($needFallback) {
                $fullText = implode("\n", $lines);

                // Coba extract sender dari "Pengirim:" di teks
                if ($senderName === null && preg_match('/Pengirim\s*[:\-]\s*([^\n]+)/i', $fullText, $fm)) {
                    $senderRaw = trim($fm[1]);
                    if (preg_match('/^(.+?)\s+(\d{10,15})$/', $senderRaw, $sp)) {
                        $senderName = trim($sp[1]) ?: null;
                    } elseif (preg_match('/(\b\d{10,15}\b)/', $senderRaw, $sp)) {
                        $senderName = trim(str_replace($sp[0], '', $senderRaw)) ?: null;
                    } else {
                        $senderName = $senderRaw ?: null;
                    }
                }

                // Jika buyer_name sama dengan sender_name, berarti regex
                // "Penerima:" salah tangkap data pengirim. Coba perbaiki buyer.
                if ($buyerName !== null && $senderName !== null
                    && mb_strtolower($buyerName) === mb_strtolower($senderName)) {
                    // Cari semua occurrence "Penerima:" di teks — mungkin ada yang
                    // punya nama berbeda dari sender.
                    if (preg_match_all('/Penerima\s*[:\-]\s*([^\n]+)/i', $fullText, $allPm)) {
                        $foundBuyer = null;
                        foreach ($allPm[1] as $penerimaLine) {
                            $penerimaLine = trim($penerimaLine);
                            // Hapus "Pengirim:..." jika ada di baris yang sama
                            $penerimaLine = preg_replace('/\s*Pengirim\s*[:\-].*$/i', '', $penerimaLine);
                            $penerimaLine = trim($penerimaLine);
                            // Strip HP
                            if (preg_match('/^(.+?)\s+(\d{10,15})$/', $penerimaLine, $pp)) {
                                $candidate = trim($pp[1]);
                                if ($candidate !== '' && mb_strtolower($candidate) !== mb_strtolower($senderName)) {
                                    $foundBuyer = $candidate;
                                    $buyerPhone = $buyerPhone ?: trim($pp[2]);
                                    break;
                                }
                            } elseif ($penerimaLine !== '' && mb_strtolower($penerimaLine) !== mb_strtolower($senderName)) {
                                $foundBuyer = $penerimaLine;
                                break;
                            }
                        }

                        if ($foundBuyer !== null) {
                            $buyerName = $foundBuyer;
                        } else {
                            // Nama penerima asli tidak ada di teks — set null
                            // supaya tidak menampilkan nama pengirim sebagai penerima.
                            $buyerName = null;
                        }
                    } else {
                        $buyerName = null;
                    }
                }
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

    private function extractResi(string $text, string $marketplace = 'tiktok'): ?string
    {
        // Shopee: SPXID + 10+ digit
        if (preg_match_all('/\b(SPXID\d{10,20})\b/i', $text, $matches)) {
            $counts = array_count_values(array_map('strtoupper', $matches[1]));
            arsort($counts);

            return array_key_first($counts);
        }

        // TikTok/J&T Express: JX/JP + 10-16 digit
        if (preg_match_all('/\b(J[XP]\d{10,16})\b/i', $text, $matches)) {
            $counts = array_count_values(array_map('strtoupper', $matches[1]));
            arsort($counts);

            return array_key_first($counts);
        }

        // J&T Cargo / Tokopedia / FastTrack: nomor resi numerik 10-16 digit,
        // biasanya muncul sebagai barcode utama tepat di bawah header
        // "J&T CARGO" / "FastTrack" / "TBN-..." routing code.
        // Coba ambil angka panjang yang paling sering muncul.
        $isCargo = preg_match('/J&T\s*CARGO|FastTrack/i', $text) === 1;
        if ($isCargo || $marketplace === 'tokopedia') {
            // Format 1: token TBN-XXXX-XX (sortation code, BUKAN resi tapi
            // bisa jadi fallback unik).
            $sortToken = null;
            if (preg_match('/\b(TBN-[A-Z0-9]+-[A-Z0-9]+)\b/i', $text, $sm)) {
                $sortToken = strtoupper(trim($sm[1]));
            }

            // Format 2: resi numerik 10-16 digit. Pilih yang paling sering muncul
            // dan abaikan angka yang jelas-jelas Order ID (biasanya 16+ digit
            // diawali "5840" pada TikTok atau didahului label "Order Id").
            if (preg_match_all('/\b(\d{10,16})\b/', $text, $matches)) {
                $orderIds = [];
                if (preg_match_all('/(?:TT\s*Order\s*ID|Order\s*Id)\s*[:\-]?\s*(\d{10,24})/i', $text, $oidMatches)) {
                    $orderIds = array_map('trim', $oidMatches[1]);
                }
                $candidates = array_diff($matches[1], $orderIds);
                $candidates = array_filter($candidates, fn ($n) => strlen($n) >= 10 && strlen($n) <= 16);

                if (! empty($candidates)) {
                    $counts = array_count_values($candidates);
                    arsort($counts);

                    return (string) array_key_first($counts);
                }
            }

            if ($sortToken) {
                return $sortToken;
            }
        }

        // Fallback generik
        if (preg_match('/(?:Resi|No\.?\s*Resi|AWB)\s*[:\-]?\s*([A-Z0-9\-]{10,24})/i', $text, $m)) {
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

        // TikTok di J&T Cargo: "TT Order ID : 58405...46303" — prioritaskan ini
        // karena lebih jelas TikTok-nya.
        if (preg_match('/TT\s*Order\s*ID\s*[:\-]?\s*(\d{10,24})/i', $text, $m)) {
            return trim($m[1]);
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
        // J&T Cargo (FastTrack) — cek dulu sebelum J&T Express
        if (preg_match('/J&T\s*CARGO|J\s*&\s*T\s*CARGO|FastTrack/i', $text)) {
            return 'JNT_CARGO';
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
     * TikTok: tabel "Product Name SKU Seller SKU Qty".
     *
     * Dua layout didukung:
     *  - Inline: "Stir Racing  R14  Coklat,Stir+Bosskit  1"
     *    (kolom dipisah 2+ spasi, qty di ujung baris yang sama)
     *  - Multi-line: tiap kolom bisa wrap ke beberapa baris, qty
     *    berdiri sendiri di satu baris. Contoh:
     *       Stir Racing GAZOO RACING Ring    <- name part 1
     *       14 & +Bosskit                     <- name part 2
     *       Coklat,                            <- seller_sku part 1
     *       Stir+Bossk                         <- seller_sku part 2
     *       it                                 <- mid-word wrap
     *       1                                  <- qty (baris sendiri)
     *
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

        // Coba parser multiline dulu (tahan wrap + qty di baris sendiri)
        $rows = $this->parseTiktokMultilineRows($block);

        if (empty($rows)) {
            $rows = $this->parseTiktokInlineRows($block);
        }

        return $rows;
    }

    /**
     * Parser TikTok legacy: qty di ujung baris yang sama, kolom dipisah 2+ spasi.
     *
     * @return array<int, array<string, mixed>>
     */
    private function parseTiktokInlineRows(string $block): array
    {
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
     * Parser TikTok multi-line: qty terletak di baris sendiri, nama dan
     * seller_sku dapat tersebar di beberapa baris. Beda dengan Shopee,
     * TikTok tidak punya nomor urut baris.
     *
     * @return array<int, array<string, mixed>>
     */
    private function parseTiktokMultilineRows(string $block): array
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $block)), fn ($l) => $l !== ''));
        if (empty($lines)) {
            return [];
        }

        $rows = [];
        $buffer = [];

        foreach ($lines as $line) {
            // Pure-digit line = qty di baris sendiri → flush buffer sebagai row.
            if (preg_match('/^(\d{1,4})$/', $line)) {
                if (! empty($buffer)) {
                    $qty = (int) $line;
                    $row = $this->buildTiktokRowFromBuffer($buffer, $qty, false);
                    if ($row !== null) {
                        $rows[] = $row;
                    }
                    $buffer = [];
                }
                continue;
            }

            $buffer[] = $line;
        }

        // Flush sisa buffer: baris terakhir berakhir " <digit>" (qty inline).
        //   Kasus: seller_sku + qty ada di baris terakhir yang sama.
        //   Contoh layout label ini:
        //     Stir Racing New Skeleton Import   <- nama part 1
        //     R14" Black                         <- nama part 2
        //     Stir Aja 1                         <- seller_sku + qty
        if (! empty($buffer)) {
            $lastLine = end($buffer);
            if (preg_match('/^(.+?)\s+(\d{1,4})$/', $lastLine, $inlineMatch)) {
                $qty = (int) $inlineMatch[2];
                $buffer[array_key_last($buffer)] = trim($inlineMatch[1]);
                $row = $this->buildTiktokRowFromBuffer($buffer, $qty, true);
                if ($row !== null) {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    /**
     * Susun row TikTok dari buffer baris-konten + qty.
     *
     * $qtyWasInline menandakan qty ter-split dari ujung baris terakhir buffer.
     * Ketika TRUE, baris terakhir buffer dianggap sebagai seller_sku (kolom
     * terakhir) dan baris-baris di atasnya adalah product_name. Ini meng-
     * cover layout di mana SKU kosong & seller_sku cukup pendek sehingga
     * tampil di baris yang sama dengan qty (mis. "Stir Aja 1").
     *
     * Ketika FALSE (qty berdiri di baris sendiri), pakai heuristik koma:
     * baris pertama yang mengandung ',' = awal seller_sku.
     *
     * @param array<int, string> $buffer
     * @return ?array<string, mixed>
     */
    private function buildTiktokRowFromBuffer(array $buffer, int $qty, bool $qtyWasInline = false): ?array
    {
        if (empty($buffer)) {
            return null;
        }

        // Kasus 1-line: buffer cuma punya 1 baris → layout legacy inline,
        //   split by 2+ spaces (kolom Product Name / SKU / Seller SKU).
        if (count($buffer) === 1) {
            $rest = trim($buffer[0]);
            $cols = preg_split('/\s{2,}/', $rest) ?: [];
            if (count($cols) >= 3) {
                return [
                    'product_name' => trim($cols[0]),
                    'sku' => trim($cols[1]) ?: null,
                    'seller_sku' => trim($cols[2]) ?: null,
                    'quantity' => $qty,
                    'raw_line' => $rest,
                ];
            }
            // Kalau cuma 1 kolom, simpan apa adanya
            return [
                'product_name' => $rest,
                'sku' => null,
                'seller_sku' => null,
                'quantity' => $qty,
                'raw_line' => $rest,
            ];
        }

        // Kasus qty inline di baris terakhir:
        //   Baris terakhir (setelah qty di-strip) = seller_sku kolom.
        //   Baris-baris sebelumnya = product_name.
        // Ini cocok untuk layout label tanpa SKU dan tanpa koma di seller_sku,
        // mis. "Stir Aja 1" (seller_sku="Stir Aja", qty=1).
        if ($qtyWasInline) {
            $lastContent = trim((string) end($buffer));
            if ($lastContent !== '') {
                $nameParts = array_slice($buffer, 0, -1);
                $sellerSku = trim($lastContent);
                $name = $this->smartJoinShopeeLines($nameParts);

                if ($name !== '' && mb_strlen($name) >= 3) {
                    return [
                        'product_name' => $name,
                        'sku' => null,
                        'seller_sku' => $sellerSku ?: null,
                        'quantity' => $qty,
                        'raw_line' => trim(implode(' | ', $buffer).' | qty='.$qty),
                    ];
                }
            }
            // Fall through ke heuristik koma kalau gagal (mis. nama terlalu pendek)
        }

        // Kasus qty di baris sendiri: baris pertama yang mengandung ',' = awal
        // seller_sku (khas TikTok: "Coklat,Stir+Bosskit").
        $sellerSkuStart = null;
        foreach ($buffer as $i => $line) {
            if ($i === 0) {
                continue; // baris pertama selalu nama
            }
            if (str_contains($line, ',')) {
                $sellerSkuStart = $i;
                break;
            }
        }

        if ($sellerSkuStart !== null) {
            $nameParts = array_slice($buffer, 0, $sellerSkuStart);
            $sellerSkuParts = array_slice($buffer, $sellerSkuStart);
        } else {
            $nameParts = $buffer;
            $sellerSkuParts = [];
        }

        $name = $this->smartJoinShopeeLines($nameParts);
        $sellerSku = $this->smartJoinShopeeLines($sellerSkuParts);

        if ($name === '' || mb_strlen($name) < 3) {
            return null;
        }

        return [
            'product_name' => $name,
            'sku' => null,
            'seller_sku' => $sellerSku ?: null,
            'quantity' => $qty,
            'raw_line' => trim(implode(' | ', $buffer).' | qty='.$qty),
        ];
    }

    /**
     * Shopee: tabel "# Nama Produk SKU Variasi Qty".
     *
     * Strategi multi-fallback:
     *   (A) Blok antara header "Qty\n" dan "Pesan:" / "No.Pesanan" / \z
     *       — paling stabil, mengatasi kasus No.Pesanan diletakkan di bawah Pesan.
     *   (B) Blok antara "No.Pesanan: XXX" dan "Pesan:" — layout Shopee lama.
     *   (C) Seluruh teks halaman.
     *
     * Setiap blok diparse dengan:
     *   1. parseShopeeMultilineRows — qty berdiri sendiri di satu baris,
     *      nama/SKU/variasi bisa wrap ke beberapa baris (termasuk mid-word).
     *   2. parseShopeeRowsFromBlock — qty di ujung baris yang sama (layout lama).
     *   3. parseShopeeSingleLine — seluruh row jadi satu baris panjang.
     *
     * @return array<int, array<string, mixed>>
     */
    private function extractShopeeProductRows(string $text): array
    {
        // (A) Anchor kuat: header tabel "Qty\n" → seller-note "Pesan:" / dll.
        //     Dipakai duluan karena No.Pesanan kadang diletakkan DI BAWAH Pesan.
        $block = null;
        if (preg_match(
            '/\bQty\b\s*\n(.+?)(?:Pesan\s*[:\(]|Order\s*ID\s*[:\-]|No\.?\s*Pesanan\s*[:\-]|\z)/is',
            $text,
            $m
        )) {
            $block = $m[1];
        }

        // (B) Fallback: setelah "No.Pesanan: XXX" sampai "Pesan:" (layout lama).
        if ($block === null && preg_match(
            '/No\.?\s*Pesanan\s*[:\-]\s*[A-Z0-9]+\s*\n?(.+?)(?:Pesan\s*[:\(]|Order\s*ID\s*[:\-]|\z)/is',
            $text,
            $m
        )) {
            $block = $m[1];
        }

        // (C) Seluruh teks — akan difilter per baris.
        if ($block === null) {
            $block = $text;
        }

        // Parse: multi-line (qty di baris sendiri) dulu, baru fallback ke legacy.
        $rows = $this->parseShopeeMultilineRows((string) $block);

        if (empty($rows)) {
            $rows = $this->parseShopeeRowsFromBlock((string) $block);
        }

        // Fallback terakhir: teks PDF satu baris panjang tanpa newline.
        if (empty($rows)) {
            $rows = $this->parseShopeeSingleLine($text);
        }

        return $rows;
    }

    /**
     * Parse block Shopee di mana tiap kolom (nama / SKU / variasi / qty) bisa
     * wrap ke baris sendiri. Layout typical:
     *
     *   1Stir kayu Palang ...   <- index + nama (kadang tanpa spasi)
     *   Ring 15 &14 inc         <- sambungan nama
     *   R14                     <- SKU
     *   Silver,Dus+bubl         <- variasi
     *   e                       <- sambungan variasi (wrap mid-word)
     *   1                       <- qty (baris sendiri)
     *
     * @return array<int, array<string, mixed>>
     */
    private function parseShopeeMultilineRows(string $block): array
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $block)), fn ($l) => $l !== ''));
        if (empty($lines)) {
            return [];
        }

        // 1. Buang baris noise yang bisa nyasar ke dalam blok produk.
        $lines = array_values(array_filter($lines, function ($line) {
            return ! preg_match(
                '/^(SPXID\d+|J[XP]\d{8,}|LOP[- ]?[A-Z]?[- ]?\d+|V\s*[-]\s*\d+|ECO$|COD$|Shop$|tokopedia|Pesan\s*[:\(]|Order\s*ID\s*[:\-]|No\.?\s*Pesanan|Pengirim\s*[:\-]?|#\s*Nama\s*Produk|Nama\s*Produk\s+SKU|Variasi\s+Qty|Qty\s*Total|Batas\s*Kirim|Berat\s*[:\-])/i',
                $line
            );
        }));

        // 2. Split index yang nempel ke nama: "1Stir kayu..." -> ["1", "Stir kayu..."]
        $normalized = [];
        foreach ($lines as $line) {
            if (preg_match('/^(\d{1,2})([A-Za-z].+)$/u', $line, $m)) {
                $normalized[] = $m[1];
                $normalized[] = trim($m[2]);
            } else {
                $normalized[] = $line;
            }
        }

        // 3. Baca token: pure digit = batas row (index baru ATAU qty row sekarang).
        //    Tapi kalau baris konten berakhir " <digit>" (qty inline), flush
        //    langsung tanpa menunggu baris digit terpisah.
        $rows = [];
        $buffer = [];
        $rowStarted = false;

        foreach ($normalized as $line) {
            if (preg_match('/^\d{1,3}$/', $line)) {
                $num = (int) $line;

                if (! $rowStarted && empty($buffer)) {
                    // Awal row: angka ini adalah nomor urut.
                    $rowStarted = true;
                    continue;
                }

                if (! empty($buffer)) {
                    // Angka ini adalah qty — akhiri row sekarang.
                    $row = $this->buildShopeeRowFromBuffer($buffer, $num);
                    if ($row !== null) {
                        $rows[] = $row;
                    }
                    $buffer = [];
                    $rowStarted = false;
                    continue;
                }

                // Buffer kosong tapi rowStarted=true → dua angka berturut,
                // anggap angka kedua sebagai index ulang. Skip.
                continue;
            }

            // Cek apakah baris konten ini punya qty inline di ujung (mis. "Tombol Klakson 1")
            // Ini terjadi ketika produk tidak punya SKU/variasi, qty langsung di ujung nama.
            if ($rowStarted && preg_match('/^(.+?)\s+(\d{1,3})$/', $line, $inlineMatch)) {
                $contentPart = trim($inlineMatch[1]);
                $inlineQty = (int) $inlineMatch[2];

                // Hanya anggap sebagai qty inline kalau konten sebelum angka bukan
                // murni kode pendek (min 5 char) — supaya "Ring 15" tidak salah split.
                // Trik: kita hanya pakai ini kalau TIDAK ada baris lain yang menyusul
                // (artinya baris ini satu-satunya konten untuk row ini).
                // Untuk safety, masukkan ke buffer dulu — flush di akhir loop kalau
                // tidak ada digit terpisah menyusul.
                $buffer[] = $line;
                continue;
            }

            $buffer[] = $line;
        }

        // 4. Flush buffer yang tersisa: kalau buffer berisi konten dan baris terakhirnya
        //    berakhir dengan angka (qty inline), strip qty dari baris tersebut dan flush.
        if (! empty($buffer) && $rowStarted) {
            $lastLine = end($buffer);
            if (preg_match('/^(.+?)\s+(\d{1,3})$/', $lastLine, $inlineMatch)) {
                $inlineQty = (int) $inlineMatch[2];
                // Ganti baris terakhir buffer dengan versi tanpa qty
                $buffer[array_key_last($buffer)] = trim($inlineMatch[1]);
                $row = $this->buildShopeeRowFromBuffer($buffer, $inlineQty);
                if ($row !== null) {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    /**
     * Susun row Shopee dari buffer baris-konten + qty yang sudah diketahui.
     *
     * Heuristik:
     *   - Cari baris yang "terlihat seperti SKU": token pendek alfanumerik
     *     tanpa spasi/koma/plus, minimal 1 huruf. Baris pertama dilewati
     *     (itu nama produk), baris terakhir juga tidak diprioritaskan.
     *   - nama = semua baris SEBELUM SKU, digabung dengan smart-join.
     *   - variasi = semua baris SESUDAH SKU, digabung dengan smart-join.
     *   - Kalau SKU tidak ketemu, baris terakhir yang mengandung ',' atau '+'
     *     dianggap variasi; sisanya nama.
     *
     * @param array<int, string> $buffer
     * @return ?array<string, mixed>
     */
    private function buildShopeeRowFromBuffer(array $buffer, int $qty): ?array
    {
        if (empty($buffer)) {
            return null;
        }

        $skuIdx = null;
        foreach ($buffer as $i => $line) {
            if ($i === 0) {
                // Baris pertama = nama produk, jangan diklaim SKU.
                continue;
            }
            if (mb_strlen($line) > 20) {
                continue;
            }
            if (str_contains($line, ' ') || str_contains($line, ',') || str_contains($line, '+')) {
                continue;
            }
            if (! preg_match('/^[A-Z0-9][A-Z0-9\-_\.]{0,14}$/i', $line)) {
                continue;
            }
            if (! preg_match('/[A-Za-z]/', $line)) {
                // Pure digits: kemungkinan sisa nomor, bukan SKU.
                continue;
            }
            $skuIdx = $i;
            break;
        }

        $sku = null;
        if ($skuIdx !== null) {
            $nameParts = array_slice($buffer, 0, $skuIdx);
            $sku = $buffer[$skuIdx];
            $variationParts = array_slice($buffer, $skuIdx + 1);
        } else {
            // Tidak ketemu SKU: coba pisah baris terakhir sebagai variasi
            // kalau mengandung koma/plus (ciri khas variasi Shopee).
            $lastIdx = count($buffer) - 1;
            if ($lastIdx > 0 && preg_match('/[,+]/', $buffer[$lastIdx])) {
                $nameParts = array_slice($buffer, 0, $lastIdx);
                $variationParts = [$buffer[$lastIdx]];
            } else {
                $nameParts = $buffer;
                $variationParts = [];
            }
        }

        $name = $this->smartJoinShopeeLines($nameParts);
        $variation = $this->smartJoinShopeeLines($variationParts);

        if ($name === '' || mb_strlen($name) < 3) {
            return null;
        }

        return [
            'product_name' => $name,
            'sku' => $sku ?: null,
            'seller_sku' => $variation ?: null,
            'quantity' => $qty,
            'raw_line' => trim(implode(' | ', $buffer).' | qty='.$qty),
        ];
    }

    /**
     * Smart-join: kalau baris BERIKUTNYA sangat pendek (<=3 char) dan
     * ekstensi mid-word yang wajar (huruf kecil, tanpa spasi/tanda baca)
     * sementara baris sebelumnya juga berakhir dengan huruf/angka — gabung
     * tanpa spasi (line-wrap mid-word). Selain itu, gabung dengan spasi.
     *
     * Contoh mid-word wrap (join tanpa spasi):
     *   ["Silver,Dus+bubl", "e"] -> "Silver,Dus+buble"
     *
     * Contoh baris lanjutan biasa (join dengan spasi):
     *   ["Kemeja batik pria modern", "lengan panjang"]
     *     -> "Kemeja batik pria modern lengan panjang"
     *
     * @param array<int, string> $parts
     */
    private function smartJoinShopeeLines(array $parts): string
    {
        $parts = array_values(array_filter(array_map('trim', $parts), fn ($p) => $p !== ''));
        if (empty($parts)) {
            return '';
        }

        $result = $parts[0];
        $count = count($parts);
        for ($i = 1; $i < $count; $i++) {
            $curr = $parts[$i];

            $isMidWordWrap = preg_match('/[A-Za-z0-9]$/u', $result)
                && mb_strlen($curr) <= 3
                && preg_match('/^[a-z]+$/u', $curr);

            if ($isMidWordWrap) {
                $result .= $curr;
            } else {
                $result .= ' '.$curr;
            }
        }

        return trim($result);
    }

    /**
     * Parse block multi-line: cari baris yang diakhiri angka qty (1-3 digit)
     * dan diawali nomor urut (atau nomor urut di tengah kalau wrap).
     *
     * @return array<int, array<string, mixed>>
     */
    private function parseShopeeRowsFromBlock(string $block): array
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $block))));

        $rows = [];
        $buffer = [];

        foreach ($lines as $line) {
            // Skip baris yang jelas noise (resi, footer)
            if (preg_match('/^(SPXID\d+|LOP[- ][A-Z]?[- ]?\d+|V\s*[-]\s*\d+|Shop\s*$|tokopedia|Pesan\s*[:\(]|Order\s*ID\s*[:\-]|#\s*Nama\s*Produk|Nama\s*Produk\s+SKU|Variasi\s+Qty|^Qty\s*Total)/i', $line)) {
                $buffer = [];
                continue;
            }

            $buffer[] = $line;

            // Apakah baris ini punya qty di ujung? (spasi + angka 1-3 digit di akhir)
            if (! preg_match('/\s(\d{1,3})\s*$/', $line, $matchQty)) {
                continue;
            }

            $joined = implode(' ', $buffer);

            // Cari nomor urut di awal ATAU di tengah (kalau buffer kemasukan noise)
            //   Pola: "^1 " atau " 1 Stir..."
            if (preg_match('/(?:^|\s)(\d{1,2})\s+(\S.+)$/', $joined, $startMatch, PREG_OFFSET_CAPTURE)) {
                $startOffset = (int) $startMatch[0][1];
                $fromStart = ltrim(substr($joined, $startOffset));

                // Harus benar-benar diawali digit
                if (preg_match('/^\d{1,2}\s+/', $fromStart)) {
                    $row = $this->parseShopeeRowLine($fromStart);
                    if ($row !== null) {
                        $rows[] = $row;
                        $buffer = [];
                        continue;
                    }
                }
            }

            // Reset buffer kalau baris ini ketemu qty tapi tidak fit sebagai row
            $buffer = [];
        }

        return $rows;
    }

    /**
     * Fallback: teks PDF kadang jadi satu baris panjang tanpa newline.
     * Cari pola "1 <name> <sku> <variasi> <qty>" dengan regex global.
     *
     * @return array<int, array<string, mixed>>
     */
    private function parseShopeeSingleLine(string $text): array
    {
        $rows = [];

        // Ambil substring setelah "No.Pesanan:" kalau ada, supaya cuma kena
        // blok produk, bukan resi berulang.
        if (preg_match('/No\.?\s*Pesanan\s*[:\-]\s*[A-Z0-9]+(.+?)(?:Pesan\s*[:\(]|Order\s*ID|\z)/is', $text, $m)) {
            $text = $m[1];
        }

        // Pattern: nomor-urut  nama-produk  variasi-mengandung-koma-atau-plus  qty
        // Contoh: "1 Stir kayu Palang ... R14 Silver,Dus+buble 1"
        //   - ^\d+\s - nomor urut
        //   - (.+?) - nama produk (lazy)
        //   - (\S+[\+,][\S,+]*) - variasi: token mengandung + atau ,
        //   - \s(\d{1,3})\b - qty
        if (preg_match_all(
            '/\b(\d{1,2})\s+(.+?)(?:\s+([A-Z0-9][A-Z0-9\-_]{1,14}))?\s+(\S*[,+][\S,+]*)\s+(\d{1,3})\b/u',
            $text,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                $name = trim($m[2]);
                $sku = trim($m[3] ?? '');
                $variation = trim($m[4]);
                $qty = (int) $m[5];

                // Filter noise: name minimal 8 karakter supaya bukan angka random
                if (mb_strlen($name) < 5) {
                    continue;
                }

                $rows[] = [
                    'product_name' => $name,
                    'sku' => $sku ?: null,
                    'seller_sku' => $variation ?: null,
                    'quantity' => $qty,
                    'raw_line' => trim($m[0]),
                ];
            }
        }

        return $rows;
    }

    /**
     * Parse satu baris row Shopee yang sudah yakin diawali nomor urut.
     *
     * @return ?array<string, mixed>
     */
    private function parseShopeeRowLine(string $joined): ?array
    {
        // Buang nomor urut
        if (! preg_match('/^\d{1,2}\s+(.+)$/s', $joined, $m)) {
            return null;
        }
        $rest = trim($m[1]);

        // Qty di akhir
        if (! preg_match('/\s(\d{1,3})\s*$/', $rest, $mq)) {
            return null;
        }
        $qty = (int) $mq[1];
        $rest = trim(preg_replace('/\s\d{1,3}\s*$/', '', $rest));

        // Strategi 1: Split by 2+ whitespace
        $cols = preg_split('/\s{2,}/', $rest) ?: [];
        if (count($cols) >= 3) {
            return [
                'product_name' => trim($cols[0]),
                'sku' => trim($cols[1]) ?: null,
                'seller_sku' => trim($cols[2]) ?: null,
                'quantity' => $qty,
                'raw_line' => $rest,
            ];
        }

        // Strategi 2: scan dari kanan (heuristik)
        [$productName, $sku, $variation] = $this->splitShopeeRowFromRight($rest);

        // Validasi: produk minimal 5 char, supaya tidak tertukar dengan kode
        if (! $productName || mb_strlen($productName) < 5) {
            return null;
        }

        return [
            'product_name' => trim($productName),
            'sku' => $sku ? trim($sku) : null,
            'seller_sku' => $variation ? trim($variation) : null,
            'quantity' => $qty,
            'raw_line' => $rest,
        ];
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

    /**
     * Customer Message muncul di halaman ke-2 label J&T Express bulk-print
     * (TikTok Shop / Tokopedia). Format: "Customer Message: <pesan dari pembeli>".
     * Field ini SEPARATE dari Seller Note dan dipakai untuk merging ke primary
     * page lewat consolidatePages().
     */
    private function extractCustomerMessage(string $text): ?string
    {
        if (preg_match('/Customer\s*Message\s*[:\-]\s*([^\n]+)/i', $text, $m)) {
            $note = trim($m[1]);
            if ($note !== '') {
                return $note;
            }
        }

        return null;
    }
}
