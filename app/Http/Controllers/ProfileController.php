<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProfileController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($request->user())],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:max_width=4096,max_height=4096'],
            'username' => ['prohibited'],
            'role' => ['prohibited'],
            'perusahaan_id' => ['prohibited'],
            'password' => ['prohibited'],
        ]);
        unset($data['avatar']);
        $avatar = null;
        $oldAvatar = null;
        try {
            $user = DB::transaction(function () use ($request, $data, &$avatar, &$oldAvatar): User {
                $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
                if ($user->email !== $data['email']) {
                    $user->email_verified_at = null;
                }
                if ($request->hasFile('avatar')) {
                    $avatar = $request->file('avatar')->store('users/'.$user->id.'/avatar', 'local');
                    if (! $avatar) {
                        throw ValidationException::withMessages(['avatar' => 'Foto gagal disimpan. Silakan coba lagi.']);
                    }
                    $oldAvatar = $user->avatar;
                    $user->avatar = $avatar;
                }
                $user->fill($data)->save();

                return $user;
            });
        } catch (Throwable $exception) {
            if ($avatar) {
                Storage::disk('local')->delete($avatar);
            }
            throw $exception;
        }
        if ($avatar && $oldAvatar && str_starts_with($oldAvatar, 'users/'.$user->id.'/avatar/') && ! str_contains($oldAvatar, '..')) {
            Storage::disk('local')->delete($oldAvatar);
        }

        return response()->json([
            'message' => 'Profil berhasil diperbarui.',
            'user' => ['name' => $user->name, 'email' => $user->email, 'avatar_url' => $user->avatar ? route('users.avatar', $user).'?v='.hash('sha256', $user->avatar) : null],
        ])->header('Cache-Control', 'no-store, private');
    }

    public function password(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed', 'different:current_password'],
        ], ['current_password.current_password' => 'Password sebelumnya tidak sesuai.']);
        DB::transaction(function () use ($request, $data): void {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            if (! Hash::check($data['current_password'], $user->password)) {
                throw ValidationException::withMessages(['current_password' => 'Password sebelumnya tidak sesuai.']);
            }
            $user->password = $data['password'];
            $user->remember_token = Str::random(60);
            $user->save();
        });
        $request->session()->regenerate();

        return response()->json(['message' => 'Password berhasil diubah.', 'csrf_token' => csrf_token()])->header('Cache-Control', 'no-store, private');
    }
}
