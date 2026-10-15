<?php

namespace App\Http\Controllers;

use App\Models\Perusahaan;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:255']]);
        $search = trim($data['q'] ?? '');
        $editingId = $request->old('_user_id');

        return view('mcu.users', [
            'users' => User::with('perusahaan')->when($search !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($search): void {
                $query->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%')->orWhere('username', 'like', '%'.$search.'%');
            }))->orderBy('name')->orderBy('id')->paginate(30)->withQueryString(),
            'search' => $search,
            'roles' => ['admin' => 'Admin', 'petugas' => 'Petugas', 'hrd' => 'HRD'],
            'companies' => Perusahaan::orderBy('nama_perusahaan')->get(),
            'editingUser' => is_string($editingId) && Str::isUuid($editingId) ? User::find($editingId) : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $avatar = null;
        try {
            DB::transaction(function () use ($request, $data, &$avatar): void {
                $user = User::create($data);
                if ($request->hasFile('avatar')) {
                    $avatar = $this->storeAvatar($request->file('avatar'), $user);
                    $user->avatar = $avatar;
                    $user->save();
                }
            });
        } catch (Throwable $exception) {
            if ($avatar) {
                Storage::disk('local')->delete($avatar);
            }
            throw $exception;
        }

        return redirect()->route('users.index')->with('success', 'User berhasil ditambahkan.');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $this->validated($request, $user);
        $avatar = null;
        $oldAvatar = null;
        try {
            DB::transaction(function () use ($request, $data, $user, &$avatar, &$oldAvatar): void {
                $this->lockAdmins();
                $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                if ($user->role === 'admin' && $data['role'] !== 'admin' && User::where('role', 'admin')->count() <= 1) {
                    throw ValidationException::withMessages(['role' => 'Admin terakhir tidak dapat diubah menjadi role lain.']);
                }
                if (isset($data['password'])) {
                    $user->remember_token = Str::random(60);
                }
                if ($request->hasFile('avatar')) {
                    $avatar = $this->storeAvatar($request->file('avatar'), $user);
                    $oldAvatar = $user->avatar;
                    $user->avatar = $avatar;
                }
                $user->fill($data)->save();
            });
        } catch (Throwable $exception) {
            if ($avatar) {
                Storage::disk('local')->delete($avatar);
            }
            throw $exception;
        }
        if ($avatar && $oldAvatar && str_starts_with($oldAvatar, 'users/'.$user->id.'/avatar/')) {
            Storage::disk('local')->delete($oldAvatar);
        }

        return redirect()->route('users.index')->with('success', 'User berhasil diperbarui.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        DB::transaction(function () use ($request, $user): void {
            $this->lockAdmins();
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($reason = $this->deletionRestriction($user, $request->user())) {
                throw ValidationException::withMessages(['user' => $reason]);
            }
            $user->delete();
        });

        return redirect()->route('users.index')->with('success', 'User berhasil dihapus.');
    }

    public function avatar(Request $request, User $user): StreamedResponse
    {
        abort_unless($request->user()->is($user) || $request->user()->can('manage-mcu'), 403);
        abort_unless($user->avatar && str_starts_with($user->avatar, 'users/'.$user->id.'/avatar/') && ! str_contains($user->avatar, '..') && Storage::disk('local')->exists($user->avatar), 404);

        return Storage::disk('local')->response($user->avatar, null, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function storeAvatar(UploadedFile $file, User $user): string
    {
        $path = $file->store('users/'.$user->id.'/avatar', 'local');
        if (! $path) {
            throw ValidationException::withMessages(['avatar' => 'Foto user gagal disimpan. Silakan coba lagi.']);
        }

        return $path;
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'list', 'min:1', 'max:30'],
            'ids.*' => ['required', 'uuid', 'distinct'],
            'q' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $results = DB::transaction(function () use ($data, $request): array {
            $this->lockAdmins();
            $results = [];
            foreach ($data['ids'] as $id) {
                $user = User::whereKey($id)->lockForUpdate()->first();
                $reason = $user ? $this->deletionRestriction($user, $request->user()) : 'User sudah dihapus atau tidak ditemukan.';
                if ($reason === null) {
                    $user->delete();
                }
                $results[] = ['name' => $user?->name ?? $id, 'deleted' => $reason === null, 'reason' => $reason ?? 'Berhasil dihapus.'];
            }

            return $results;
        });
        $deleted = count(array_filter($results, fn (array $result): bool => $result['deleted']));

        return redirect()->route('users.index', array_intersect_key($data, array_flip(['q', 'page'])))
            ->with('success', $deleted.' user dihapus, '.(count($results) - $deleted).' dilewati.')
            ->with('bulkDeleteResults', $results);
    }

    private function lockAdmins(): void
    {
        User::where('role', 'admin')->orderBy('id')->lockForUpdate()->get();
    }

    private function deletionRestriction(User $user, User $actor): ?string
    {
        if ($user->is($actor)) {
            return 'Anda tidak dapat menghapus akun sendiri.';
        }
        if ($user->role === 'admin' && User::where('role', 'admin')->count() <= 1) {
            return 'Admin terakhir tidak dapat dihapus.';
        }

        return null;
    }

    /** @return array{name: string, username: ?string, email: string, role: string, password?: string} */
    private function validated(Request $request, ?User $user = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('users', 'username')->ignore($user)],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user)],
            'role' => ['required', Rule::in(['admin', 'petugas', 'hrd'])],
            'perusahaan_id' => ['nullable', 'required_if:role,hrd', 'uuid', Rule::exists('perusahaans', 'id')->whereNull('deleted_at')],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:8', 'max:255', 'confirmed'],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:max_width=4096,max_height=4096'],
        ]);
        unset($data['avatar']);
        $data['perusahaan_id'] = $data['role'] === 'hrd' ? ($data['perusahaan_id'] ?? null) : null;
        if (empty($data['password'])) {
            unset($data['password']);
        }

        return $data;
    }
}
