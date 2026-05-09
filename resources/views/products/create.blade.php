@extends('layouts.app')
@section('title', 'Produk Baru')

@section('content')
    @php($header = 'Produk Baru')

    <div class="card max-w-2xl">
        <form method="POST" action="{{ route('products.store') }}" class="space-y-4">
            @csrf
            <div>
                <label class="label">Nama Produk</label>
                <input name="name" value="{{ old('name') }}" required class="input">
                @error('name')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="label">SKU</label>
                <input name="sku" value="{{ old('sku') }}" required class="input font-mono">
                @error('sku')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="label">Deskripsi</label>
                <textarea name="description" rows="3" class="input">{{ old('description') }}</textarea>
            </div>
            <label class="inline-flex items-center gap-2 text-sm">
                <input type="checkbox" name="is_active" value="1" checked class="rounded border-gray-300">
                Aktif
            </label>

            <div class="flex gap-2">
                <button class="btn-primary" type="submit">Simpan & Tambah Varian</button>
                <a href="{{ route('products.index') }}" class="btn-secondary">Batal</a>
            </div>
        </form>
    </div>
@endsection
