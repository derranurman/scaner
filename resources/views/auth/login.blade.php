<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-gradient-to-br from-indigo-50 to-white grid place-items-center px-4">
    <div class="w-full max-w-md">
        <div class="text-center mb-6">
            <div class="h-12 w-12 rounded-xl bg-indigo-600 text-white grid place-items-center font-bold text-xl mx-auto">S</div>
            <h1 class="mt-3 text-2xl font-bold">Scaner Toko</h1>
            <p class="text-sm text-gray-500">Masuk untuk mengelola stok & scan resi</p>
        </div>

        <div class="card">
            <form method="POST" action="{{ route('login') }}" class="space-y-4">
                @csrf
                <div>
                    <label class="label" for="email">Email</label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus class="input">
                    @error('email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="label" for="password">Password</label>
                    <input id="password" name="password" type="password" required class="input">
                    @error('password') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <label class="flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" name="remember" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                    Ingat saya
                </label>

                <button type="submit" class="btn-primary w-full">Masuk</button>
            </form>
        </div>

        <div class="mt-4 text-xs text-gray-500 text-center">
            Demo akun: <br>
            <span class="font-mono">admin@toko.test / password</span><br>
            <span class="font-mono">packing@toko.test / password</span>
        </div>
    </div>
</body>
</html>
