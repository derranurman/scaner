<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PlatformDeduction;

/**
 * Hitung semua metrik ekonomi untuk 1 pesanan.
 *
 * RUMUS (sesuai spesifikasi user, Mei 2026):
 *
 *   Total Jual     = Σ(Harga Jual × Qty)
 *   Total Modal    = Σ(Harga Beli × Qty)
 *   Total Reseller = Σ(Harga Reseller × Qty)
 *
 *   // --- Potongan persentase ---
 *   ADM Rp          = min(Total Jual, 650.000) × ADM %
 *   Pajak Rp        = min(Total Jual, 650.000) × Pajak %
 *   Ongkir Free Rp  = Total Jual × Ongkir Free %
 *   Yield Rp        = Total Jual × Yield %
 *   Operasional Rp  = Total Jual × Operasional %
 *
 *   // Kolom khusus "Bulat Max 650Rb" = Ongkir Free % dengan dasar dicap 650k
 *   Bulat Max 650Rb = min(Total Jual, 650.000) × Ongkir Free %
 *
 *   // --- Agregat potongan ---
 *   Total Potongan Aplikasi = ADM + Bulat Max 650Rb + Biaya Layanan + Biaya Logistik
 *
 *   // --- Margin ---
 *   Margin Live     = Total Jual - Total Reseller
 *   Profit Kotor    = Total Jual - Total Modal - Margin Live
 *                   (= Total Reseller - Total Modal, ekuivalen)
 *
 *   Bersih Margin Live = Margin Live
 *                      - (ADM + Ongkir Free + Biaya Layanan + Biaya Logistik + Pajak + Yield)
 *
 *   Margin Bisnis   = Profit Kotor
 *                   - Total Potongan Aplikasi
 *                   - Operasional
 *                   - Plastik/Dus
 *                   - Ongkir Cargo
 *
 * Semua return value disimpan di array dengan key stabil supaya
 * blade tinggal print (tidak ada logic di view).
 */
class OrderMetricsService
{
    /** Dasar perhitungan ADM/Pajak/Bulat Max di-cap maksimal ini (aturan TikTok). */
    public const BULAT_MAX = 650_000.0;

    /**
     * @return array<string, float|string|null>
     */
    public function compute(Order $order): array
    {
        $items = $order->items()->with('variant.product')->get();

        $totalJual = 0.0;
        $totalModal = 0.0;
        $totalReseller = 0.0;
        $totalQty = 0;

        // Buat "item satuan" jadi total terkumpul berdasarkan master Product
        // (Harga Jual, Harga Beli, Harga Reseller tersimpan di Product).
        foreach ($items as $item) {
            $qty = (int) $item->quantity;
            $totalQty += $qty;

            $product = $item->variant?->product;
            if ($product) {
                $totalJual += (float) $product->selling_price * $qty;
                $totalModal += (float) $product->purchase_price * $qty;
                $totalReseller += (float) $product->reseller_price * $qty;
            }
        }

        $deduction = $order->platformDeduction;

        // Dasar yang di-cap 650k: dipakai untuk ADM, Pajak, dan Bulat Max 650Rb.
        $capBase = min($totalJual, self::BULAT_MAX);

        $admRp = 0.0;
        $cbBpRp = 0.0;
        $ongkirFreeRp = 0.0;
        $yieldRp = 0.0;
        $operasionalRp = 0.0;
        $pajakRp = 0.0;
        $bulatMax = 0.0;
        $ongkirCargo = 0.0;
        $label = 0.0;
        $plastik = 0.0;
        $biayaLayanan = 0.0;
        $biayaLogistik = 0.0;

        $admPct = 0.0;
        $cbBpPct = 0.0;
        $ongkirFreePct = 0.0;
        $yieldPct = 0.0;
        $operasionalPct = 0.0;
        $pajakPct = 0.0;

        if ($deduction instanceof PlatformDeduction) {
            $admPct = (float) $deduction->adm_percent;
            $cbBpPct = (float) $deduction->cashback_percent;
            $ongkirFreePct = (float) $deduction->free_shipping_percent;
            $yieldPct = (float) $deduction->yield_percent;
            $operasionalPct = (float) $deduction->operational_percent;
            $pajakPct = (float) $deduction->tax_percent;

            // Potongan persentase:
            //   - ADM, Pajak, Bulat Max 650Rb → dasar di-cap 650k.
            //   - Ongkir Free, Yield, Operasional → dasar Total Jual penuh.
            $admRp = $capBase * $admPct / 100;
            $cbBpRp = $totalJual * $cbBpPct / 100;
            $ongkirFreeRp = $totalJual * $ongkirFreePct / 100;
            $yieldRp = $totalJual * $yieldPct / 100;
            $operasionalRp = $totalJual * $operasionalPct / 100;
            $pajakRp = $capBase * $pajakPct / 100;
            $bulatMax = $capBase * $ongkirFreePct / 100;

            $ongkirCargo = (float) $deduction->shipping_cargo_amount;
            $label = (float) $deduction->label_amount;
            $plastik = (float) $deduction->packaging_amount;
            $biayaLayanan = (float) $deduction->service_fee_amount;
            $biayaLogistik = (float) $deduction->logistics_amount;
        }

        // Total Potongan Aplikasi = ADM + Bulat Max 650Rb + Biaya Layanan + Biaya Logistik
        $totalPotonganAplikasi = $admRp + $bulatMax + $biayaLayanan + $biayaLogistik;

        // Margin Live = Total Jual - Total Reseller
        $marginLive = $totalJual - $totalReseller;
        $pctMarginLive = $totalJual > 0 ? ($marginLive / $totalJual) * 100 : 0;

        // Profit Kotor = Total Jual - Total Modal - Margin Live
        $profitKotor = $totalJual - $totalModal - $marginLive;
        $pctProfitKotor = $totalJual > 0 ? ($profitKotor / $totalJual) * 100 : 0;

        // Bersih Margin Live = Margin Live
        //   - (ADM + Ongkir Free + Biaya Layanan + Biaya Logistik + Pajak + Yield)
        $bersihMarginLive = $marginLive
            - $admRp
            - $ongkirFreeRp
            - $biayaLayanan
            - $biayaLogistik
            - $pajakRp
            - $yieldRp;

        // Margin Bisnis = Profit Kotor
        //   - Total Potongan Aplikasi - Operasional - Plastik/Dus - Ongkir Cargo
        $marginBisnis = $profitKotor
            - $totalPotonganAplikasi
            - $operasionalRp
            - $plastik
            - $ongkirCargo;
        $pctMarginBisnis = $totalJual > 0 ? ($marginBisnis / $totalJual) * 100 : 0;

        return [
            'total_qty' => $totalQty,
            'total_jual' => $totalJual,
            'total_modal' => $totalModal,
            'total_reseller' => $totalReseller,
            'ongkir_cargo' => $ongkirCargo,
            'yield_rp' => $yieldRp,
            'plastik_dus' => $plastik,
            'operasional_rp' => $operasionalRp,
            'adm_pct' => $admPct,
            'adm_rp' => $admRp,
            'ongkir_free_pct' => $ongkirFreePct,
            'ongkir_free_rp' => $ongkirFreeRp,
            'bulat_max' => $bulatMax,
            'biaya_layanan' => $biayaLayanan,
            'biaya_logistik' => $biayaLogistik,
            'pajak_pct' => $pajakPct,
            'pajak_rp' => $pajakRp,
            'cb_bp_pct' => $cbBpPct,
            'cb_bp_rp' => $cbBpRp,
            'profit_kotor' => $profitKotor,
            'pct_profit_kotor' => $pctProfitKotor,
            'margin_bisnis' => $marginBisnis,
            'pct_margin_bisnis' => $pctMarginBisnis,
            'margin_live' => $marginLive,
            'pct_margin_live' => $pctMarginLive,
            'bersih_margin_live' => $bersihMarginLive,
            'total_potongan_aplikasi' => $totalPotonganAplikasi,
        ];
    }
}
