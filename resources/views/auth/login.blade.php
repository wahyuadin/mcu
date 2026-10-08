<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Masuk | Nayaka MCU</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-100 font-sans text-slate-900 antialiased selection:bg-[#00008B] selection:text-white">
    <main class="relative flex min-h-screen items-center justify-center bg-slate-50 p-4 sm:p-6">
        <div class="absolute inset-x-0 top-0 h-1.5 bg-[#00008B]"></div>
        <div class="grid w-full max-w-5xl overflow-hidden rounded-[2rem] border border-slate-100 bg-white shadow-2xl shadow-slate-200/60 lg:grid-cols-5">
            <section class="relative hidden flex-col justify-between bg-white p-10 lg:col-span-2 lg:flex xl:p-12">
                <div class="absolute inset-y-0 left-0 w-1 bg-[#008000]"></div>
                <div>
                    <img src="{{ asset('template/assets/logo.png') }}" alt="Nayaka Era Husada" class="h-14 w-auto object-contain">
                    <div class="mt-16">
                        <h1 class="text-3xl font-bold leading-tight text-slate-800">Medical Check Up</h1>
                        <p class="mt-4 text-sm leading-relaxed text-slate-500">Kelola dan pantau seluruh layanan operasional medical check-up secara efisien dan terintegrasi.</p>
                    </div>
                </div>
                <div class="mt-12">
                    <img src="{{ asset('template/assets/logo-iso.png') }}" alt="ISO Certified" class="mb-8 h-14 w-auto object-contain grayscale opacity-70 transition hover:grayscale-0 hover:opacity-100">
                    <div class="flex h-1.5 w-full overflow-hidden rounded-full"><div class="w-1/3 bg-[#FF8C00]"></div><div class="w-1/3 bg-[#00008B]"></div><div class="w-1/3 bg-[#008000]"></div></div>
                    <p class="mt-4 text-xs font-medium text-slate-400">&copy; {{ now()->year }} PT. Nayaka Era Husada.</p>
                </div>
            </section>
            <section class="flex flex-col justify-center bg-slate-50/50 p-8 sm:p-10 lg:col-span-3 lg:p-14 xl:p-16">
                <div class="mb-10 flex items-center justify-between lg:hidden">
                    <img src="{{ asset('template/assets/logo.png') }}" alt="Nayaka" class="h-10 w-auto object-contain">
                    <div class="flex h-1 w-16 overflow-hidden rounded-full"><div class="w-1/3 bg-[#FF8C00]"></div><div class="w-1/3 bg-[#00008B]"></div><div class="w-1/3 bg-[#008000]"></div></div>
                </div>
                <div class="mx-auto w-full max-w-sm">
                    <h2 class="text-2xl font-bold tracking-tight text-slate-900">Selamat Datang</h2>
                    <p class="mt-2 text-sm text-slate-500">Silakan masuk menggunakan akun yang sudah diberikan.</p>
                    @if($errors->any())<div role="alert" class="mt-5 rounded-xl border border-red-100 bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</div>@endif
                    <form action="{{ route('login.store') }}" method="POST" class="mt-8 space-y-5">@csrf
                        <div><label for="login" class="mb-2 block text-sm font-medium text-slate-700">Username atau Email</label><input id="login" name="login" value="{{ old('login') }}" type="text" autocomplete="username" placeholder="Masukkan username atau email" required autofocus class="block w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition placeholder:text-slate-400 focus:border-[#00008B] focus:ring-4 focus:ring-[#00008B]/10"></div>
                        <div><label for="password" class="mb-2 block text-sm font-medium text-slate-700">Password</label><div class="relative"><input id="password" name="password" type="password" autocomplete="current-password" placeholder="Masukkan password" required class="block w-full rounded-xl border border-slate-200 bg-white px-4 py-3 pr-12 text-sm outline-none transition placeholder:text-slate-400 focus:border-[#00008B] focus:ring-4 focus:ring-[#00008B]/10"><button type="button" id="toggle-password" aria-label="Tampilkan password" class="absolute inset-y-0 right-0 px-4 text-slate-400 hover:text-slate-700"><svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M2.25 12s3.75-6 9.75-6 9.75 6 9.75 6-3.75 6-9.75 6-9.75-6-9.75-6Z"/><circle cx="12" cy="12" r="2.5"/></svg></button></div></div>
                        <label class="flex cursor-pointer items-center gap-2 text-sm text-slate-600"><input name="remember" value="1" type="checkbox" class="h-4 w-4 rounded border-slate-300 accent-[#00008B]"><span>Ingat saya</span></label>
                        <button type="submit" class="w-full rounded-xl bg-[#00008B] px-4 py-3.5 text-sm font-semibold text-white shadow-lg shadow-[#00008B]/30 transition hover:bg-[#000066] focus:ring-4 focus:ring-[#00008B]/20 active:scale-[.99]">Masuk</button>
                    </form>
                </div>
            </section>
        </div>
    </main>
    <script>document.getElementById('toggle-password').addEventListener('click',function(){const input=document.getElementById('password');input.type=input.type==='password'?'text':'password';this.setAttribute('aria-label',input.type==='password'?'Tampilkan password':'Sembunyikan password')})</script>
</body>
</html>
