@extends('layouts.app')
@section('title', $user->exists ? 'Edit User' : 'User Baru')

@section('content')
    @php($header = $user->exists ? 'Edit User: '.$user->name : 'User Baru')

    <div class="card max-w-xl">
        <form method="POST" action="{{ $user->exists ? route('users.update', $user) : route('users.store') }}" class="space-y-4">
            @csrf
            @if ($user->exists) @method('PUT') @endif

            <div>
                <label class="label">Nama</label>
                <input name="name" value="{{ old('name', $user->name) }}" required class="input">
                @error('name')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="label">Email</label>
                <input type="email" name="email" value="{{ old('email', $user->email) }}" required class="input">
                @error('email')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="label">Role</label>
                <select name="role" class="input">
                    <option value="packing" @selected(old('role', $user->role) === 'packing')>Packing</option>
                    <option value="admin" @selected(old('role', $user->role) === 'admin')>Admin</option>
                </select>
            </div>
            <div>
                <label class="label">Password {{ $user->exists ? '(kosongkan jika tidak diubah)' : '' }}</label>
                <input type="password" name="password" class="input" autocomplete="new-password">
                @error('password')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
            </div>
            <label class="inline-flex items-center gap-2 text-sm">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $user->is_active)) class="rounded border-gray-300">
                Akun aktif
            </label>

            <div class="flex gap-2">
                <button class="btn-primary" type="submit">Simpan</button>
                <a href="{{ route('users.index') }}" class="btn-secondary">Batal</a>
            </div>
        </form>
    </div>
@endsection
