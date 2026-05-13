@extends('layouts.app')
@section('title', 'Edit Pesanan')

@section('content')
    <?php $header = 'Edit Pesanan'; ?>
    <?php $subheader = 'Ubah status, data pembeli, no. HP, dan detail lain dari pesanan ini.'; ?>

    <div class="card max-w-4xl">
        <form method="POST" action="{{ route('orders.update', $order) }}">
            @csrf
            @method('PUT')
            @include('orders._form', ['order' => $order, 'platforms' => $platforms, 'products' => $products])

            <div class="mt-6 flex justify-between gap-2">
                <form method="POST" action="{{ route('orders.destroy', $order) }}"
                      onsubmit="return confirm('Hapus pesanan {{ $order->resi_number }}?');">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn-secondary text-red-600 hover:bg-red-50">Hapus Pesanan</button>
                </form>
                <div class="flex gap-2">
                    <a href="{{ route('orders.index') }}" class="btn-secondary">Batal</a>
                    <button type="submit" class="btn-primary">Simpan Perubahan</button>
                </div>
            </div>
        </form>
    </div>
@endsection
