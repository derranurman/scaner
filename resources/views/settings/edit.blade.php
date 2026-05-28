@extends('layouts.app')
@section('title', 'Pengaturan')

@section('content')
    @php($header = 'Pengaturan Aplikasi')
    @php($subheader = 'Atur nama & logo yang tampil di top-nav, halaman login, dan judul browser.')

    <div class="card max-w-xl">
        <form method="POST"
              action="{{ route('settings.update') }}"
              enctype="multipart/form-data"
              class="space-y-5"
              x-data="{
                  preview: @js($setting->logoUrl()),
                  removed: false,
                  pickFile(event) {
                      const file = event.target.files[0];
                      if (!file) return;
                      this.removed = false;
                      this.preview = URL.createObjectURL(file);
                  },
                  clearFile() {
                      this.preview = null;
                      this.removed = true;
                      this.$refs.fileInput.value = '';
                  },
              }">
            @csrf
            @method('PUT')

            {{-- Logo --}}
            <div>
                <label class="label">Logo</label>
                <div class="flex items-center gap-4">
                    <div class="h-16 w-16 rounded-xl bg-indigo-600 text-white grid place-items-center overflow-hidden shrink-0">
                        <template x-if="preview">
                            <img :src="preview" alt="Logo" class="h-full w-full object-cover">
                        </template>
                        <template x-if="!preview">
                            <span class="text-2xl font-bold">{{ $setting->initial() }}</span>
                        </template>
                    </div>
                    <div class="flex-1 space-y-2">
                        <input type="file"
                               name="logo"
                               accept="image/*"
                               x-ref="fileInput"
                               @change="pickFile($event)"
                               class="block w-full text-sm file:mr-3 file:py-1.5 file:px-3 file:rounded-md file:border-0 file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100">
                        <p class="text-xs text-gray-500">JPG, PNG, GIF, atau WEBP. Maks 2 MB. Disarankan rasio 1:1 (kotak).</p>
                        @if ($setting->logo_path)
                            <label class="inline-flex items-center gap-2 text-xs text-red-600 cursor-pointer"
                                   x-show="preview || removed">
                                <input type="checkbox"
                                       name="remove_logo"
                                       value="1"
                                       x-model="removed"
                                       @change="if (removed) clearFile()"
                                       class="rounded border-red-300">
                                Hapus logo saat simpan (kembali ke huruf inisial)
                            </label>
                        @endif
                    </div>
                </div>
                @error('logo')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
            </div>

            {{-- Nama --}}
            <div>
                <label class="label">Nama Aplikasi</label>
                <input name="app_name"
                       value="{{ old('app_name', $setting->app_name) }}"
                       maxlength="60"
                       required
                       class="input"
                       placeholder="Contoh: Toko Budi">
                <p class="text-xs text-gray-500 mt-1">Tampil di top-nav, halaman login, dan judul tab browser. Maks 60 karakter.</p>
                @error('app_name')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
            </div>

            <div class="flex gap-2 pt-2 border-t">
                <button class="btn-primary" type="submit">Simpan</button>
                <a href="{{ route('dashboard') }}" class="btn-secondary">Batal</a>
            </div>
        </form>
    </div>

    {{-- Preview kecil supaya user tahu hasilnya. --}}
    <div class="mt-6">
        <div class="text-xs uppercase text-gray-500 mb-2">Preview</div>
        <div class="card max-w-xl flex items-center gap-3">
            @if ($setting->logoUrl())
                <div class="h-10 w-10 rounded-lg overflow-hidden">
                    <img src="{{ $setting->logoUrl() }}" alt="Logo" class="h-full w-full object-cover">
                </div>
            @else
                <div class="h-10 w-10 rounded-lg bg-indigo-600 text-white grid place-items-center font-bold">
                    {{ $setting->initial() }}
                </div>
            @endif
            <span class="font-semibold text-gray-900">{{ $setting->app_name }}</span>
        </div>
        <p class="text-xs text-gray-500 mt-2">Catatan: preview di atas dari nilai tersimpan; logo/nama yang baru kamu pilih baru akan muncul setelah klik <b>Simpan</b>.</p>
    </div>
@endsection
