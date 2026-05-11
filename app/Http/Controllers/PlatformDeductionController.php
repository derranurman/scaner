<?php

namespace App\Http\Controllers;

use App\Models\PlatformDeduction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PlatformDeductionController extends Controller
{
    public function index(): View
    {
        $deductions = PlatformDeduction::orderBy('platform_name')->get();

        return view('platform_deductions.index', compact('deductions'));
    }

    public function create(): View
    {
        return view('platform_deductions.form', [
            'deduction' => new PlatformDeduction(['is_active' => true]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);
        $data['is_active'] = $request->boolean('is_active', true);

        PlatformDeduction::create($data);

        return redirect()->route('platform_deductions.index')->with('success', 'Potongan platform dibuat.');
    }

    public function edit(PlatformDeduction $platform_deduction): View
    {
        return view('platform_deductions.form', ['deduction' => $platform_deduction]);
    }

    public function update(Request $request, PlatformDeduction $platform_deduction): RedirectResponse
    {
        $data = $this->validateData($request);
        $data['is_active'] = $request->boolean('is_active', false);

        $platform_deduction->update($data);

        return redirect()->route('platform_deductions.index')->with('success', 'Potongan platform diperbarui.');
    }

    public function destroy(PlatformDeduction $platform_deduction): RedirectResponse
    {
        $platform_deduction->delete();

        return back()->with('success', 'Potongan platform dihapus.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateData(Request $request): array
    {
        return $request->validate([
            'platform_name' => ['required', 'string', 'max:100'],
            'adm_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'cashback_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'free_shipping_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'shipping_cargo_amount' => ['nullable', 'numeric', 'min:0'],
            'label_amount' => ['nullable', 'numeric', 'min:0'],
            'yield_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'packaging_amount' => ['nullable', 'numeric', 'min:0'],
            'operational_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'service_fee_amount' => ['nullable', 'numeric', 'min:0'],
            'logistics_amount' => ['nullable', 'numeric', 'min:0'],
            'tax_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }
}
