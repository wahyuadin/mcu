@php($account = auth()->user())
@php($avatarUrl = $account->avatar ? route('users.avatar', $account).'?v='.hash('sha256', $account->avatar) : '')
<div data-account class="shrink-0 sm:max-w-sm">
    <button type="button" data-account-trigger popovertarget="account-menu" aria-label="Menu akun" aria-expanded="false" aria-controls="account-menu" class="flex min-h-12 max-w-full items-center gap-3 rounded-2xl bg-slate-100 p-2 text-left focus-visible:outline-2 focus-visible:outline-[#00008B]">
        <span class="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-[#00008B] text-white shadow-md">
            <img data-account-avatar @if($avatarUrl) src="{{ $avatarUrl }}" @else hidden @endif alt="Foto profil" class="h-full w-full object-cover">
            <svg data-account-avatar-icon @if($avatarUrl) hidden @endif aria-hidden="true" class="h-7 w-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4" stroke-width="1.8"/><path stroke-linecap="round" stroke-width="1.8" d="M4 21v-2a8 8 0 0 1 16 0v2"/></svg>
        </span>
        <span class="hidden min-w-0 sm:block"><strong data-account-name class="block truncate text-sm font-bold uppercase tracking-wide text-slate-800">{{ $account->name }}</strong><span class="block text-xs capitalize tracking-wide text-slate-500">{{ $account->role }}</span></span>
        <svg data-account-chevron aria-hidden="true" class="hidden h-5 w-5 shrink-0 text-slate-400 sm:block" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m6 9 6 6 6-6"/></svg>
    </button>
    <div id="account-menu" popover="auto" data-account-menu aria-label="Menu akun" class="z-50 w-80 overflow-hidden rounded-3xl border border-slate-100 bg-white text-slate-800 shadow-xl">
        <button type="button" data-account-profile-open aria-haspopup="dialog" aria-controls="account-profile-modal" class="flex min-h-16 w-full items-center gap-4 px-6 py-5 text-left text-base font-medium hover:bg-slate-50 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-[#00008B]"><svg aria-hidden="true" class="h-6 w-6 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4" stroke-width="1.8"/><path stroke-linecap="round" stroke-width="1.8" d="M4 21v-2a8 8 0 0 1 16 0v2"/></svg>Profil Saya</button>
        <form action="{{ route('logout') }}" method="POST" class="border-t border-slate-100">@csrf<button type="submit" class="flex min-h-16 w-full items-center gap-4 px-6 py-5 text-left text-base font-medium text-red-600 hover:bg-red-50 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-red-600"><svg aria-hidden="true" class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 4H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h4m6-15 5 5-5 5m-8-5h13"/></svg>Keluar</button></form>
    </div>
    <dialog id="account-profile-modal" data-user-modal data-account-dialog="profile" aria-labelledby="account-profile-title" class="fixed inset-0 m-auto max-h-[90dvh] w-[calc(100%-2rem)] max-w-4xl overflow-y-auto rounded-2xl border border-slate-200 bg-white p-6 text-slate-900 shadow-2xl sm:p-8">
        <form method="POST" enctype="multipart/form-data" action="{{ route('profile.update') }}" data-account-form="profile" data-avatar-url="{{ $avatarUrl }}" class="grid gap-6">
            @csrf @method('PATCH')
            <div class="flex items-center justify-between gap-3"><div><h2 id="account-profile-title" class="text-xl font-bold">Profil Saya</h2><p class="mt-1 text-sm text-slate-500">Perbarui informasi dan foto akun Anda.</p></div><button type="button" data-account-close aria-label="Tutup profil" class="flex h-11 w-11 items-center justify-center rounded-xl border border-slate-200">✕</button></div>
            <div class="grid gap-6 md:grid-cols-[220px_minmax(0,1fr)]">
                <section class="grid content-start gap-4 rounded-2xl border border-slate-200 bg-slate-50 p-5" aria-label="Foto profil">
                    <div class="mx-auto flex h-32 w-32 items-center justify-center overflow-hidden rounded-full bg-blue-100 text-[#00008B]">
                        <img data-profile-avatar @if($avatarUrl) src="{{ $avatarUrl }}" @else hidden @endif alt="Preview foto profil" class="h-full w-full object-cover">
                        <svg data-profile-avatar-icon @if($avatarUrl) hidden @endif aria-hidden="true" class="h-16 w-16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4" stroke-width="1.5"/><path stroke-linecap="round" stroke-width="1.5" d="M4 21v-2a8 8 0 0 1 16 0v2"/></svg>
                    </div>
                    <label class="grid gap-2 text-sm font-semibold">Upload gambar<input type="file" name="avatar" data-profile-avatar-input accept="image/jpeg,image/png,image/webp" aria-describedby="profile-avatar-help profile-error-avatar" class="min-h-11 w-full min-w-0 text-xs font-normal file:mr-2 file:rounded-lg file:border-0 file:bg-white file:px-3 file:py-3 file:font-semibold file:text-[#00008B]"></label>
                    <p id="profile-avatar-help" class="text-xs leading-5 text-slate-500">JPG, PNG, atau WebP. Maksimal 2 MB dan 4096 × 4096 piksel.</p>
                    <p id="profile-error-avatar" data-account-error="avatar" role="alert" hidden class="text-xs text-red-700"></p>
                </section>
                <div class="grid content-start gap-5 sm:grid-cols-2">
                    @foreach(['name' => ['Nama lengkap', $account->name, 'Masukkan nama lengkap', 'text'], 'email' => ['Email', $account->email, 'Contoh: nama@perusahaan.com', 'email']] as $field => [$label, $value, $placeholder, $type])
                        <label class="grid content-start gap-2 text-sm font-semibold text-slate-700">{{ $label }}<input name="{{ $field }}" type="{{ $type }}" value="{{ $value }}" placeholder="{{ $placeholder }}" required maxlength="255" aria-describedby="profile-error-{{ $field }}" class="min-h-12 w-full rounded-xl border border-slate-300 px-3 py-2 font-normal focus:border-[#00008B] focus:outline-none focus:ring-2 focus:ring-blue-100"><span id="profile-error-{{ $field }}" data-account-error="{{ $field }}" hidden class="text-xs font-normal text-red-700"></span></label>
                    @endforeach
                    <label class="grid content-start gap-2 text-sm font-semibold text-slate-700">Username<input value="{{ $account->username ?? 'Belum diatur' }}" readonly aria-readonly="true" class="min-h-12 w-full rounded-xl border border-slate-200 bg-slate-100 px-3 py-2 font-normal text-slate-500"></label>
                    <label class="grid content-start gap-2 text-sm font-semibold text-slate-700">Role<input value="{{ ucfirst($account->role) }}" readonly aria-readonly="true" class="min-h-12 w-full rounded-xl border border-slate-200 bg-slate-100 px-3 py-2 font-normal text-slate-500"></label>
                    <p class="text-xs text-slate-500 sm:col-span-2">Username dan role hanya dapat diubah oleh admin melalui pengelolaan User.</p>
                    <button type="button" data-account-password-open aria-haspopup="dialog" aria-controls="account-password-modal" class="min-h-11 justify-self-start rounded-xl border border-blue-200 bg-blue-50 px-4 py-2 text-sm font-semibold text-[#00008B]">Ganti password</button>
                </div>
            </div>
            <p data-account-status role="status" aria-live="polite" class="text-sm text-slate-600"></p>
            <div class="flex justify-end gap-3"><button type="button" data-account-close class="min-h-11 rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold">Tutup</button><button type="submit" data-account-save class="min-h-11 rounded-xl bg-[#00008B] px-5 py-2 text-sm font-semibold text-white disabled:cursor-wait disabled:opacity-60">Simpan profil</button></div>
        </form>
    </dialog>
    <dialog id="account-password-modal" data-user-modal data-account-dialog="password" aria-labelledby="account-password-title" class="fixed inset-0 m-auto max-h-[90dvh] w-[calc(100%-2rem)] max-w-xl overflow-y-auto rounded-2xl border border-slate-200 bg-white p-6 text-slate-900 shadow-2xl">
        <form method="POST" action="{{ route('profile.password') }}" data-account-form="password" class="grid gap-5">
            @csrf @method('PUT')
            <div class="flex items-center justify-between gap-3"><h2 id="account-password-title" class="text-xl font-bold">Ganti password</h2><button type="button" data-account-close aria-label="Tutup ganti password" class="flex h-11 w-11 items-center justify-center rounded-xl border border-slate-200">✕</button></div>
            <p class="text-sm text-slate-500">Masukkan password sebelumnya untuk memverifikasi perubahan.</p>
            @foreach(['current_password' => 'Password sebelumnya', 'password' => 'Password baru', 'password_confirmation' => 'Konfirmasi password baru'] as $field => $label)
                <div class="grid gap-2 text-sm font-semibold text-slate-700">
                    <label for="account-{{ $field }}">{{ $label }}</label>
                    <div class="relative"><input id="account-{{ $field }}" type="password" name="{{ $field }}" autocomplete="{{ $field === 'current_password' ? 'current-password' : 'new-password' }}" placeholder="{{ $field === 'current_password' ? 'Masukkan password sebelumnya' : ($field === 'password' ? 'Password baru minimal 8 karakter' : 'Ulangi password baru') }}" required @if($field !== 'current_password') minlength="8" maxlength="255" @endif aria-describedby="account-error-{{ $field }}" class="min-h-12 w-full rounded-xl border border-slate-300 py-2 pl-3 pr-12 font-normal focus:border-[#00008B] focus:outline-none focus:ring-2 focus:ring-blue-100">
                        <button type="button" data-account-password-toggle="{{ $field }}" data-password-label="{{ strtolower($label) }}" aria-controls="account-{{ $field }}" aria-pressed="false" aria-label="Tampilkan {{ strtolower($label) }}" class="absolute inset-y-0 right-0 flex w-12 items-center justify-center rounded-r-xl text-slate-500 focus-visible:outline-2 focus-visible:outline-[#00008B]"><svg aria-hidden="true" class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-width="1.8" d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3" stroke-width="1.8"/><path data-password-slash hidden stroke-width="1.8" d="m3 3 18 18"/></svg></button>
                    </div>
                    <span id="account-error-{{ $field }}" data-account-error="{{ $field }}" hidden class="text-xs font-normal text-red-700"></span>
                </div>
            @endforeach
            <p data-account-status role="status" aria-live="polite" class="text-sm text-slate-600"></p>
            <div class="flex justify-end gap-3"><button type="button" data-account-close class="min-h-11 rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold">Tutup</button><button type="submit" data-account-save class="min-h-11 rounded-xl bg-[#00008B] px-5 py-2 text-sm font-semibold text-white disabled:cursor-wait disabled:opacity-60">Simpan password</button></div>
        </form>
    </dialog>
</div>
