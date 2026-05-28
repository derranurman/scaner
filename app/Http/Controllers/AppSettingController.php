<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class AppSettingController extends Controller
{
    public function edit(): View
    {
        $setting = $this->settingRow();

        return view('settings.edit', compact('setting'));
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'app_name' => ['required', 'string', 'max:60'],
            'logo' => ['nullable', 'image', 'max:2048'],
            'remove_logo' => ['nullable', 'boolean'],
        ]);

        $setting = $this->settingRow();
        $shouldRemove = $request->boolean('remove_logo');

        $update = ['app_name' => $data['app_name']];

        if ($shouldRemove && $setting->logo_path) {
            Storage::disk('public')->delete($setting->logo_path);
            $update['logo_path'] = null;
        }

        if ($request->hasFile('logo')) {
            if ($setting->logo_path) {
                Storage::disk('public')->delete($setting->logo_path);
            }
            $update['logo_path'] = $request->file('logo')->store('branding', 'public');
        }

        $setting->update($update);

        return redirect()->route('settings.edit')->with('success', 'Pengaturan disimpan.');
    }

    /**
     * Pastikan baris settings ada — kalau belum (mis. migrasi seed
     * belum jalan, atau row terhapus manual) bikin sekarang. Idempotent.
     */
    private function settingRow(): AppSetting
    {
        return AppSetting::firstOrCreate(
            [],
            ['app_name' => config('app.name', 'Scaner Toko'), 'logo_path' => null]
        );
    }
}
