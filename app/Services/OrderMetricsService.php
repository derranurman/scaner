<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PlatformDeduction;

/**
 * Hitung semua metrik ekonomi untuk 1 pesanan:
 *   - Total Jual / Modal / Reseller
 *   - Potongan platform (persen & nominal) dengan aturan khusus
 *     "Bulat Max 650Rb" untuk ADM dll.
 *   - Profit Kotor & Margin Bisnis / Live.
 *
 * Semua return value disimpan di array dengan key stabil supaya
 * blade tinggal print (tidak ada logic di view).
 */
class OrderMetricsService
{
    /** Dasar perhitungan ADM dicap maksimal ini (aturan TikTok). */
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

        // Dasar ADM/Pajak: min(totalJual, BULAT_MAX) mencerminkan cap TikTok.
        $bulatMax = min($totalJual, self::BULAT_MAX);

        $admRp = 0.0;
        $cbBpRp = 0.0;
        $ongkirFreeRp = 0.0;
        $yieldRp = 0.0;
        $operasionalRp = 0.0;
        $pajakRp = 0.0;
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

            $admRp = $bulatMax * $admPct / 100;
            $cbBpRp = $totalJual * $cbBpPct / 100;
            $ongkirFreeRp = $totalJual * $ongkirFreePct / 100;
            $yieldRp = $totalJual * $yieldPct / 100;
            $operasionalRp = $totalJual * $operasionalPct / 100;
            $pajakRp = $bulatMax * $pajakPct / 100;

            $ongkirCargo = (float) $deduction->shipping_cargo_amount;
            $label = (float) $deduction->label_amount;
            $plastik = (float) $deduction->packaging_amount;
            $biayaLayanan = (float) $deduction->service_fee_amount;
            $biayaLogistik = (float) $deduction->logistics_amount;
        }

        $totalPotonganAplikasi = $admRp + $cbBpRp + $ongkirFreeRp + $yieldRp
            + $operasionalRp + $pajakRp
            + $ongkirCargo + $label + $plastik
            + $biayaLayanan + $biayaLogistik;

        // Profit Kotor = Total Jual - Total Modal
        $profitKotor = $totalJual - $totalModal;
        $pctProfitKotor = $totalJual > 0 ? ($profitKotor / $totalJual) * 100 : 0;

        // Margin Bisnis = Profit Kotor - Total Potongan Aplikasi
        $marginBisnis = $profitKotor - $totalPotonganAplikasi;
        $pctMarginBisnis = $totalJual > 0 ? ($marginBisnis / $totalJual) * 100 : 0;

        // Margin Live = Total Jual - Total Reseller - Total Potongan Aplikasi
        //   (keuntungan yang dibagikan ke host live setelah potongan aplikasi)
        $marginLive = $totalJual - $totalReseller - $totalPotonganAplikasi;
        $pctMarginLive = $totalJual > 0 ? ($marginLive / $totalJual) * 100 : 0;

        // Bersih Margin Live = Margin Live - (komisi / fee host, default 0).
        //   Saat ini belum ada kolom komisi host, jadi sama dengan Margin Live.
        $bersihMarginLive = $marginLive;

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
