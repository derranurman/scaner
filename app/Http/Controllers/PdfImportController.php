<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PdfParseDraft;
use App\Services\ParsedOrderResolver;
use App\Services\TiktokLabelParser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PdfImportController extends Controller
{
    public function __construct(
        private TiktokLabelParser $parser,
        private ParsedOrderResolver $resolver,
    ) {
    }

    public function show(): View
    {
        $recentDrafts = PdfParseDraft::with('user')
            ->latest()
            ->limit(10)
            ->get();

        return view('orders.import_pdf', compact('recentDrafts'));
    }

    public function upload(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:pdf', 'max:20480'],
        ]);

        $path = $request->file('file')->getRealPath();

        try {
            $rawPages = $this->parser->parseFile($path);
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'Gagal membaca PDF: '.$e->getMessage());
        }

        if (empty($rawPages)) {
            return back()->with('error', 'Tidak ada resi yang terdeteksi di PDF. Pastikan format label TikTok Shop + J&T.');
        }

        // Resolve tiap halaman ke items dengan combo/sku match
        $enriched = [];
        foreach ($rawPages as $page) {
            $resolution = $this->resolver->resolve($page);
            $enriched[] = [
                'page' => $page['page'],
                'resi_number' => $page['resi_number'],
                'tiktok_order_id' => $page['tiktok_order_id'],
                'courier' => $page['courier'],
                'buyer_name' => $page['buyer_name'],
                'buyer_phone' => $page['buyer_phone'],
                'shipping_address' => $page['shipping_address'],
                'weight' => $page['weight'],
                'order_date' => $page['order_date'],
                'barang_keyword' => $page['barang_keyword'],
                'seller_note' => $page['seller_note'],
                'items' => $resolution['items'],
                'warnings' => $resolution['warnings'],
                'matched_keyword' => $resolution['matchedKeyword'],
                'already_exists' => Order::where('resi_number', $page['resi_number'])->exists(),
            ];
        }

        $draft = PdfParseDraft::create([
            'user_id' => $request->user()->id,
            'original_filename' => $request->file('file')->getClientOriginalName(),
            'total_pages' => count($rawPages),
            'status' => PdfParseDraft::STATUS_DRAFT,
            'parsed_orders' => $enriched,
        ]);

        return redirect()->route('orders.import.pdf.preview', $draft)
            ->with('success', 'Berhasil parse '.count($rawPages).' halaman. Cek pratinjau di bawah.');
    }

    public function preview(PdfParseDraft $draft): View
    {
        abort_if($draft->status !== PdfParseDraft::STATUS_DRAFT, 404, 'Draft ini sudah tidak aktif.');

        return view('orders.import_pdf_preview', compact('draft'));
    }

    public function commit(Request $request, PdfParseDraft $draft): RedirectResponse
    {
        abort_if($draft->status !== PdfParseDraft::STATUS_DRAFT, 404);

        $selectedIndexes = (array) $request->input('selected', []);
        if (empty($selectedIndexes)) {
            return back()->with('error', 'Pilih minimal 1 pesanan untuk disimpan.');
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];

        DB::transaction(function () use ($draft, $selectedIndexes, &$created, &$updated, &$skipped, &$errors) {
            foreach ($draft->parsed_orders as $index => $entry) {
                if (! in_array((string) $index, array_map('strval', $selectedIndexes), true)) {
                    continue;
                }

                $resi = $entry['resi_number'];
                if (! $resi) {
                    $skipped++;
                    $errors[] = "Halaman {$entry['page']}: resi tidak terdeteksi.";
                    continue;
                }

                $existing = Order::where('resi_number', $resi)->first();
                if ($existing && $existing->status === Order::STATUS_PACKED) {
                    $skipped++;
                    $errors[] = "Resi {$resi}: sudah dipacking, dilewati.";
                    continue;
                }

                $orderData = [
                    'tiktok_order_id' => $entry['tiktok_order_id'],
                    'courier' => $entry['courier'] ?: 'JNT',
                    'buyer_name' => $entry['buyer_name'],
                    'buyer_phone' => $entry['buyer_phone'],
                    'shipping_address' => $entry['shipping_address'],
                    'order_date' => $entry['order_date'] ?: now(),
                    'status' => Order::STATUS_PENDING,
                    'notes' => $entry['seller_note'],
                ];

                if ($existing) {
                    $existing->update($orderData);
                    $existing->items()->delete();
                    $order = $existing;
                    $updated++;
                } else {
                    $order = Order::create($orderData + ['resi_number' => $resi]);
                    $created++;
                }

                foreach ($entry['items'] as $item) {
                    OrderItem::create([
                        'order_id' => $order->id,
                        'variant_id' => $item['variant_id'],
                        'product_name' => $item['product_name'] ?: '—',
                        'variant_name' => $item['variant_name'],
                        'sku' => $item['sku'],
                        'quantity' => max(1, (int) $item['quantity']),
                    ]);
                }
            }

            $draft->update(['status' => PdfParseDraft::STATUS_COMMITTED]);
        });

        if (! empty($errors)) {
            session()->flash('import_errors', $errors);
        }

        return redirect()->route('orders.index')
            ->with('success', "Import PDF selesai: {$created} baru, {$updated} diperbarui, {$skipped} dilewati.");
    }

    public function discard(PdfParseDraft $draft): RedirectResponse
    {
        $draft->update(['status' => PdfParseDraft::STATUS_DISCARDED]);

        return redirect()->route('orders.import.pdf.show')->with('success', 'Draft dibuang.');
    }
}
