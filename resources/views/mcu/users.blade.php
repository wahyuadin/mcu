@extends('layouts.mcu')
@section('title', 'User')
@section('content')
<div class="mx-auto grid max-w-6xl gap-6" data-user-management>
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div><h2 class="text-2xl font-bold">Pengelolaan user</h2><p class="mt-2 text-sm text-slate-500">Kelola akun dan hak akses sistem MCU.</p></div>
        <button type="button" data-user-create aria-haspopup="dialog" aria-controls="user-form-modal" class="min-h-11 rounded-xl bg-[#00008B] px-5 py-3 text-sm font-bold text-white">+ Tambah user</button>
    </div>
    <section data-bulk-delete class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <form method="GET" action="{{ route('users.index') }}" class="flex flex-wrap items-end gap-3 border-b border-slate-100 p-6">
            <label class="grid w-full min-w-0 gap-2 text-sm font-semibold sm:w-auto sm:flex-1">Cari user<input type="search" name="q" value="{{ $search }}" maxlength="255" placeholder="Cari nama, email, atau username…" class="min-h-12 min-w-0 rounded-xl border border-slate-300 bg-white px-3"></label>
            <button type="submit" class="min-h-11 rounded-xl border border-slate-300 px-5 py-3 text-sm font-semibold">Cari</button>
            <a href="{{ route('users.index') }}" class="min-h-11 rounded-xl border border-slate-300 px-5 py-3 text-sm font-semibold">Reset</a>
        </form>
        @include('mcu.master-bulk-delete', ['bulkRoute' => 'users.bulk-destroy', 'bulkFilters' => ['q', 'page'], 'bulkHelp' => 'Pilih semua hanya berlaku untuk halaman ini. Akun sendiri dan admin terakhir tidak dapat dihapus.'])
        <div class="overflow-x-auto">
            <table aria-label="Daftar user" class="w-full min-w-[850px] text-left text-sm">
                <thead class="border-b border-slate-100 bg-slate-50 text-xs uppercase tracking-wider text-slate-500"><tr>@include('mcu.master-select-all')<th scope="col" class="px-5 py-4">Nama</th><th scope="col" class="px-5 py-4">Username</th><th scope="col" class="px-5 py-4">Email</th><th scope="col" class="px-5 py-4">Role</th><th scope="col" class="px-5 py-4 text-right">Aksi</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($users as $user)
                        <tr class="hover:bg-blue-50/30">
                            @include('mcu.master-select-row', ['selectionId' => $user->id, 'selectionName' => $user->name])
                            <th scope="row" class="max-w-64 px-5 py-4 align-top font-semibold text-slate-800"><div class="flex items-center gap-3"><span class="flex h-11 w-11 shrink-0 items-center justify-center overflow-hidden rounded-full bg-blue-50 text-[#00008B]">@if($user->avatar)<img src="{{ route('users.avatar', $user) }}" alt="Foto {{ $user->name }}" class="h-full w-full object-cover">@else<svg aria-hidden="true" class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4" stroke-width="1.8"/><path stroke-linecap="round" stroke-width="1.8" d="M4 21v-2a8 8 0 0 1 16 0v2"/></svg>@endif</span><span class="min-w-0 break-words">{{ $user->name }}@if($user->is(auth()->user()))<span class="mt-1 block text-xs font-normal text-slate-500">Akun Anda</span>@endif</span></div></th>
                            <td class="max-w-48 break-words px-5 py-4 align-top text-slate-600">{{ $user->username ?? '—' }}</td>
                            <td class="max-w-64 break-words px-5 py-4 align-top text-slate-600">{{ $user->email }}</td>
                            <td class="max-w-64 px-5 py-4 align-top"><span class="whitespace-nowrap rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-[#00008B]">{{ $roles[$user->role] ?? $user->role }}</span>@if($user->role === 'hrd')<p class="mt-2 break-words text-xs text-slate-500">{{ $user->perusahaan?->nama_perusahaan ?? 'Perusahaan tidak tersedia — akses dinonaktifkan' }}</p>@endif</td>
                            <td class="px-5 py-4 text-right align-top">
                                <div class="flex justify-end gap-2">
                                    <button type="button" data-user-edit="{{ json_encode(['id' => $user->id, 'name' => $user->name, 'username' => $user->username, 'email' => $user->email, 'role' => $user->role, 'perusahaan_id' => $user->perusahaan_id, 'avatar_url' => $user->avatar ? route('users.avatar', $user) : null, 'url' => route('users.update', $user)]) }}" aria-label="Ubah {{ $user->name }}" aria-haspopup="dialog" aria-controls="user-form-modal" class="inline-flex min-h-11 items-center gap-2 rounded-xl border border-blue-200 bg-blue-50 px-3 py-2 text-xs font-semibold text-[#00008B] hover:bg-blue-100"><svg aria-hidden="true" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m16 4 4 4M4 20l4-1L20 7a2.8 2.8 0 0 0-4-4L4 15v5Z"/></svg>Ubah</button>
                                    @unless($user->is(auth()->user()))
                                        <button type="button" data-user-delete data-delete-name="{{ $user->name }}" data-delete-url="{{ route('users.destroy', $user) }}" aria-label="Hapus {{ $user->name }}" aria-haspopup="dialog" aria-controls="user-delete-modal" class="inline-flex min-h-11 items-center gap-2 rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-xs font-semibold text-red-700 hover:bg-red-100"><svg aria-hidden="true" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15M10 10v7m4-7v7"/></svg>Hapus</button>
                                    @endunless
                                </div>
                            </td>
                        </tr>
                    @empty<tr><td colspan="6" class="px-6 py-12 text-center text-slate-500">{{ $search !== '' ? 'Tidak ada user yang cocok dengan pencarian.' : 'Belum ada user.' }}</td></tr>@endforelse
                </tbody>
            </table>
        </div>
        @if($users->hasPages())<div class="border-t border-slate-100 px-6 py-4">{{ $users->links() }}</div>@endif
        <dialog id="user-bulk-delete-modal" data-user-modal data-bulk-delete-confirm aria-labelledby="user-bulk-delete-title" class="fixed inset-0 m-auto max-h-[90dvh] w-[calc(100%-2rem)] max-w-md overflow-y-auto rounded-2xl border border-slate-200 bg-white p-6 text-slate-900 shadow-2xl">
            <h2 id="user-bulk-delete-title" class="text-xl font-bold">Hapus user terpilih?</h2>
            <p class="mt-3 text-sm text-slate-600">Anda akan menghapus <strong data-confirm-count>0</strong> user. Akun yang dihapus tidak dapat login lagi. Akun sendiri dan admin terakhir akan dilewati.</p>
            <div class="mt-6 flex justify-end gap-3"><button type="button" data-user-modal-close autofocus class="min-h-11 rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold">Batal</button><button type="button" data-confirm-submit class="min-h-11 rounded-xl bg-red-700 px-4 py-2 text-sm font-semibold text-white">Ya, hapus terpilih</button></div>
        </dialog>
    </section>

    <dialog id="user-form-modal" data-user-modal data-user-form-modal data-reopen="{{ $errors->hasAny(['name', 'username', 'email', 'role', 'perusahaan_id', 'password', 'password_confirmation', 'avatar']) ? 'true' : 'false' }}" aria-labelledby="user-form-title" class="fixed inset-0 m-auto max-h-[90dvh] w-[calc(100%-2rem)] max-w-5xl overflow-y-auto rounded-2xl border border-slate-200 bg-white p-6 text-slate-900 shadow-2xl sm:p-8">
        <form method="POST" enctype="multipart/form-data" action="{{ $editingUser ? route('users.update', $editingUser) : route('users.store') }}" data-user-form data-create-url="{{ route('users.store') }}" data-avatar-url="{{ $editingUser?->avatar ? route('users.avatar', $editingUser) : '' }}" class="grid gap-6">
            @csrf
            <input type="hidden" name="_method" value="{{ $editingUser ? 'PUT' : 'POST' }}">
            <input type="hidden" name="_user_id" value="{{ $editingUser?->id }}">
            <div class="flex items-center justify-between gap-3"><h2 id="user-form-title" class="text-xl font-bold">{{ $editingUser ? 'Ubah user' : 'Tambah user' }}</h2><button type="button" data-user-modal-close aria-label="Tutup form user" class="flex h-11 w-11 items-center justify-center rounded-xl border border-slate-200">✕</button></div>
            <div class="grid gap-6 md:grid-cols-[240px_minmax(0,1fr)]">
                <section class="grid content-start gap-4 rounded-2xl border border-slate-200 bg-slate-50 p-5" aria-label="Foto user">
                    <h3 class="text-sm font-semibold">Foto user</h3>
                    <div class="mx-auto flex h-32 w-32 items-center justify-center overflow-hidden rounded-full border-4 border-white bg-blue-100 text-[#00008B] shadow-sm">
                        <img data-user-avatar-preview @if($editingUser?->avatar) src="{{ route('users.avatar', $editingUser) }}" @else hidden @endif alt="Preview foto user" class="h-full w-full object-cover">
                        <svg data-user-avatar-placeholder @if($editingUser?->avatar) hidden @endif aria-hidden="true" class="h-16 w-16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4" stroke-width="1.5"/><path stroke-linecap="round" stroke-width="1.5" d="M4 21v-2a8 8 0 0 1 16 0v2"/></svg>
                    </div>
                    <label class="grid gap-2 text-sm font-semibold">Upload gambar<input type="file" name="avatar" accept="image/jpeg,image/png,image/webp" data-user-avatar-input class="min-h-11 w-full min-w-0 text-xs font-normal file:mr-2 file:rounded-lg file:border-0 file:bg-white file:px-3 file:py-3 file:font-semibold file:text-[#00008B]"></label>
                    <p class="text-xs leading-5 text-slate-500">JPG, PNG, atau WebP. Maksimal 2 MB dan 4096 × 4096 piksel. Tanpa foto, ikon user digunakan.</p>
                    <p data-user-avatar-message role="alert" hidden class="text-xs text-red-700"></p>
                    @error('avatar')<p data-user-field-error class="text-xs text-red-700">{{ $message }} Pilih ulang gambar sebelum menyimpan.</p>@enderror
                </section>
                <div class="grid content-start gap-5 sm:grid-cols-2">
                    @include('mcu.field', ['field' => 'name', 'label' => 'Nama (wajib)', 'placeholder' => 'Masukkan nama lengkap', 'value' => $editingUser?->name])
                    @include('mcu.field', ['field' => 'username', 'label' => 'Username (opsional)', 'placeholder' => 'Contoh: wahyu.adinugroho', 'value' => $editingUser?->username])
                    @include('mcu.field', ['field' => 'email', 'label' => 'Email (wajib)', 'placeholder' => 'Contoh: nama@perusahaan.com', 'type' => 'email', 'value' => $editingUser?->email])
                    @include('mcu.field', ['field' => 'role', 'label' => 'Role (wajib)', 'type' => 'select', 'selectPlaceholder' => 'Pilih role user', 'value' => $editingUser?->role ?? 'petugas', 'options' => $roles])
                    <label data-user-company-field class="grid gap-2 text-sm font-semibold sm:col-span-2" @if(old('role', $editingUser?->role) !== 'hrd') hidden @endif>Perusahaan HRD<select name="perusahaan_id" class="min-h-12 min-w-0 rounded-xl border border-slate-300 bg-white px-3"><option value="">Pilih perusahaan untuk akun HRD</option>@foreach($companies as $company)<option value="{{ $company->id }}" @selected(old('perusahaan_id', $editingUser?->perusahaan_id) === $company->id)>{{ $company->nama_perusahaan }}</option>@endforeach</select>@error('perusahaan_id')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
                    <p class="text-xs leading-5 text-slate-500 sm:col-span-2">Username hanya boleh berisi huruf, angka, titik, garis bawah, dan tanda hubung. Login juga bisa menggunakan email.</p>
                    @foreach(['password' => 'Password', 'password_confirmation' => 'Konfirmasi password'] as $field => $label)
                        <div class="grid content-start gap-2 text-sm font-semibold text-slate-700">
                            <label for="user-{{ $field }}">{{ $label }}</label>
                            <div class="relative">
                                <input id="user-{{ $field }}" type="password" name="{{ $field }}" autocomplete="new-password" placeholder="{{ $field === 'password' ? 'Masukkan password (minimal 8 karakter)' : 'Ulangi password yang sama' }}" minlength="8" maxlength="255" @required(!$editingUser) class="min-h-12 w-full rounded-xl border border-slate-300 py-2 pl-3 pr-12 font-normal focus:border-[#00008B] focus:outline-none focus:ring-2 focus:ring-blue-100">
                                <button type="button" data-password-toggle="{{ $field }}" data-password-label="{{ strtolower($label) }}" aria-label="Tampilkan {{ strtolower($label) }}" aria-controls="user-{{ $field }}" aria-pressed="false" class="absolute inset-y-0 right-0 flex w-12 items-center justify-center rounded-r-xl text-slate-500 hover:text-[#00008B] focus-visible:outline-2 focus-visible:outline-[#00008B]">
                                    <svg data-password-hidden aria-hidden="true" class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-width="1.8" d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3" stroke-width="1.8"/></svg>
                                    <svg data-password-visible hidden aria-hidden="true" class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-width="1.8" d="m3 3 18 18M10.6 5.1 12 5c6.5 0 10 7 10 7a19 19 0 0 1-3 3.7M6.5 6.5C3.5 8.7 2 12 2 12s3.5 7 10 7a11 11 0 0 0 5.5-1.5M10 10a3 3 0 0 0 4 4"/></svg>
                                </button>
                            </div>
                            @error($field)<span data-user-field-error class="text-xs font-normal text-red-700">{{ $message }}</span>@enderror
                        </div>
                    @endforeach
                    <p data-user-password-help class="text-xs text-slate-500 sm:col-span-2">{{ $editingUser ? 'Kosongkan password jika tidak ingin mengubahnya.' : 'Password minimal 8 karakter.' }}</p>
                </div>
            </div>
            <div class="flex justify-end gap-3"><button type="button" data-user-modal-close class="min-h-11 rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold">Batal</button><button type="submit" class="min-h-11 rounded-xl bg-[#00008B] px-5 py-2 text-sm font-semibold text-white">Simpan user</button></div>
        </form>
    </dialog>
    <dialog id="user-delete-modal" data-user-modal data-user-delete-modal aria-labelledby="user-delete-title" class="fixed inset-0 m-auto max-h-[90dvh] w-[calc(100%-2rem)] max-w-md overflow-y-auto rounded-2xl border border-slate-200 bg-white p-6 text-slate-900 shadow-2xl">
        <h2 id="user-delete-title" class="text-xl font-bold">Hapus user?</h2>
        <p class="mt-3 break-words text-sm text-slate-600">Hapus akun <strong data-user-delete-name></strong>? Akun yang dihapus tidak dapat login lagi. Riwayat aktivitas tetap tersimpan.</p>
        <form method="POST" data-user-delete-form class="mt-6 flex justify-end gap-3">@csrf @method('DELETE')<button type="button" data-user-modal-close autofocus class="min-h-11 rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold">Batal</button><button type="submit" class="min-h-11 rounded-xl bg-red-700 px-4 py-2 text-sm font-semibold text-white">Ya, hapus</button></form>
    </dialog>
</div>
@endsection
