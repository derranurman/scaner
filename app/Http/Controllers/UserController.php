<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(): View
    {
        $users = User::orderBy('name')->paginate(20);

        return view('users.index', compact('users'));
    }

    public function create(): View
    {
        return view('users.form', ['user' => new User(['role' => User::ROLE_PACKING, 'is_active' => true])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'unique:users,email'],
            'role' => ['required', 'in:admin,packing'],
            'is_active' => ['nullable', 'boolean'],
            'password' => ['required', Password::min(6)],
        ]);

        $data['is_active'] = $request->boolean('is_active', true);
        $data['password'] = Hash::make($data['password']);

        User::create($data);

        return redirect()->route('users.index')->with('success', 'User dibuat.');
    }

    public function edit(User $user): View
    {
        return view('users.form', compact('user'));
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'unique:users,email,'.$user->id],
            'role' => ['required', 'in:admin,packing'],
            'is_active' => ['nullable', 'boolean'],
            'password' => ['nullable', Password::min(6)],
        ]);

        $data['is_active'] = $request->boolean('is_active', false);

        if (! empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        $user->update($data);

        return redirect()->route('users.index')->with('success', 'User diperbarui.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->id === $request->user()->id) {
            return back()->with('error', 'Tidak bisa menghapus akun sendiri.');
        }

        $user->delete();

        return back()->with('success', 'User dihapus.');
    }
}
