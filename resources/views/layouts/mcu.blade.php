<!doctype html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Dashboard') | Nayaka MCU</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="min-h-screen bg-slate-100 font-sans text-slate-900 antialiased">
    <div class="fixed inset-x-0 top-0 z-50 h-1.5 bg-[#00008B]"></div>
    <div class="flex min-h-screen bg-slate-100">
        <aside id="sidebar" class="fixed inset-y-0 left-0 z-50 mt-1.5 flex w-72 -translate-x-full flex-col overflow-hidden border-r border-slate-200/70 bg-white shadow-2xl shadow-slate-900/10 transition-transform duration-300 lg:static lg:z-40 lg:translate-x-0 lg:shadow-none">
            <div class="flex h-20 shrink-0 items-center justify-between border-b border-slate-100 px-6">
                <a href="{{ route('dashboard') }}" class="flex items-center gap-3"><img src="{{ asset('template/assets/logo.png') }}" alt="Nayaka" class="h-9 w-auto object-contain"></a>
                <button type="button" id="close-sidebar" class="flex h-9 w-9 items-center justify-center rounded-xl text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-700 lg:hidden" aria-label="Tutup menu"><svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg></button>
            </div>

            <nav class="flex-1 space-y-6 overflow-y-auto px-4 py-5 text-sm">
                <div class="space-y-1.5">
                    <p class="px-3 pb-1 text-[10px] font-extrabold uppercase tracking-[0.18em] text-slate-400">Ringkasan
                    </p>
                    <a data-sidebar-link href="{{ route('dashboard') }}" class="group flex items-center gap-3 rounded-xl px-3 py-2.5 font-semibold transition-colors {{ request()->routeIs('dashboard') ? 'bg-[#00008B] text-white shadow-lg shadow-blue-900/15' : 'text-slate-600 hover:bg-slate-100 hover:text-[#00008B]' }}"><span class="flex h-9 w-9 items-center justify-center rounded-lg {{ request()->routeIs('dashboard') ? 'bg-white/15' : 'bg-slate-100 group-hover:bg-blue-50' }}"><svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l9-9 9 9M5 10v10h14V10M9 20v-6h6v6" />
                            </svg></span><span>Dashboard</span></a>
                </div>

                <div class="space-y-1.5">
                    <p class="px-3 pb-1 text-[10px] font-extrabold uppercase tracking-[0.18em] text-slate-400">
                        Operasional MCU</p>
                    @can('view-patients')
                    <a data-sidebar-link href="{{ route('kunjungan-mcu.index') }}" class="group flex items-center gap-3 rounded-xl px-3 py-2.5 font-semibold transition-colors {{ request()->routeIs('kunjungan-mcu.*', 'pemeriksaan.edit', 'pemeriksaan.scan') ? 'bg-[#00008B] text-white shadow-lg shadow-blue-900/15' : 'text-slate-600 hover:bg-slate-100 hover:text-[#00008B]' }}"><span class="flex h-9 w-9 items-center justify-center rounded-lg {{ request()->routeIs('kunjungan-mcu.*', 'pemeriksaan.edit', 'pemeriksaan.scan') ? 'bg-white/15' : 'bg-slate-100 group-hover:bg-blue-50' }}"><svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3M5 11h14M5 5h14a2 2 0 012 2v12a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2zm3 10 2 2 4-4" />
                            </svg></span><span>Kunjungan MCU</span></a>
                    @endcan
                    @can('view-patients')
                    <a data-sidebar-link href="{{ route('laporan-riwayat.index') }}" class="group flex items-center gap-3 rounded-xl px-3 py-2.5 font-semibold transition-colors {{ request()->routeIs('laporan-riwayat.*', 'pemeriksaan.laporan-riwayat*') ? 'bg-[#00008B] text-white shadow-lg shadow-blue-900/15' : 'text-slate-600 hover:bg-slate-100 hover:text-[#00008B]' }}"><span class="flex h-9 w-9 items-center justify-center rounded-lg {{ request()->routeIs('laporan-riwayat.*', 'pemeriksaan.laporan-riwayat*') ? 'bg-white/15' : 'bg-slate-100 group-hover:bg-blue-50' }}"><svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6M7 3h6l4 4v14H7z" />
                            </svg></span><span>Laporan & riwayat</span></a>
                    @endcan
                </div>

                @canany(['view-patients', 'manage-mcu'])
                <div class="space-y-1.5">
                    <p class="px-3 pb-1 text-[10px] font-extrabold uppercase tracking-[0.18em] text-slate-400">Master
                        data</p>
                    @can('manage-mcu')
                    <details @if (request()->routeIs('paket.*', 'mcu-category.*', 'mcu-subcategory.*', 'mcu-item.*')) open @endif class="group/paket">
                        <summary class="group flex cursor-pointer list-none items-center gap-3 rounded-xl px-3 py-2.5 font-semibold transition-colors [&::-webkit-details-marker]:hidden {{ request()->routeIs('paket.*', 'mcu-category.*', 'mcu-subcategory.*', 'mcu-item.*') ? 'bg-[#00008B] text-white shadow-lg shadow-blue-900/15' : 'text-slate-600 hover:bg-slate-100 hover:text-[#00008B]' }}">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg {{ request()->routeIs('paket.*', 'mcu-category.*', 'mcu-subcategory.*', 'mcu-item.*') ? 'bg-white/15' : 'bg-slate-100 group-hover:bg-blue-50' }}"><svg aria-hidden="true" class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                                </svg></span>
                            <span class="flex-1">Paket MCU</span>
                            <svg aria-hidden="true" class="h-4 w-4 shrink-0 group-open/paket:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m6 9 6 6 6-6" />
                            </svg>
                        </summary>
                        <div class="ml-7 mt-2 grid gap-1.5 border-l border-slate-200 pb-1 pl-4">
                            @foreach ([['paket.index', 'paket.*', 'Daftar Paket'], ['mcu-category.index', 'mcu-category.*', 'Master Kategori'], ['mcu-subcategory.index', 'mcu-subcategory.*', 'Master Subkategori'], ['mcu-item.index', 'mcu-item.*', 'Master Item']] as [$route, $pattern, $label])
                            <a data-sidebar-link href="{{ route($route) }}" @if (request()->routeIs($pattern)) aria-current="page" @endif
                                class="rounded-xl px-3 py-2.5 text-sm font-semibold transition-colors {{ request()->routeIs($pattern) ? 'bg-blue-50 text-[#00008B]' : 'text-slate-600 hover:bg-slate-100 hover:text-[#00008B]' }}">{{ $label }}</a>
                            @endforeach
                        </div>
                    </details>
                    @endcan
                    @can('view-patients')
                    <a data-sidebar-link href="{{ route('pasien.index') }}" class="group flex items-center gap-3 rounded-xl px-3 py-2.5 font-semibold transition-colors {{ request()->routeIs('pasien.*') ? 'bg-[#00008B] text-white shadow-lg shadow-blue-900/15' : 'text-slate-600 hover:bg-slate-100 hover:text-[#00008B]' }}"><span class="flex h-9 w-9 items-center justify-center rounded-lg {{ request()->routeIs('pasien.*') ? 'bg-white/15' : 'bg-slate-100 group-hover:bg-blue-50' }}"><svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2m10-13a4 4 0 11-8 0 4 4 0 018 0zm7 3v6m3-3h-6" />
                            </svg></span><span>Pasien</span></a>
                    @endcan
                    @can('manage-mcu')
                    <a data-sidebar-link href="{{ route('rencana-kunjungan.index') }}" class="group flex items-center gap-3 rounded-xl px-3 py-2.5 font-semibold transition-colors {{ request()->routeIs('rencana-kunjungan.*') ? 'bg-[#00008B] text-white shadow-lg shadow-blue-900/15' : 'text-slate-600 hover:bg-slate-100 hover:text-[#00008B]' }}"><span class="flex h-9 w-9 items-center justify-center rounded-lg {{ request()->routeIs('rencana-kunjungan.*') ? 'bg-white/15' : 'bg-slate-100 group-hover:bg-blue-50' }}"><svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 012-2h2a2 2 0 012 2M9 5a2 2 0 002 2h2a2 2 0 002-2m-4 7h6m-6 4h6" />
                            </svg></span><span>Rencana Kunjungan</span></a>
                    @endcan
                    @can('manage-mcu')
                    <a data-sidebar-link href="{{ route('perusahaan.index') }}" class="group flex items-center gap-3 rounded-xl px-3 py-2.5 font-semibold transition-colors {{ request()->routeIs('perusahaan.*') ? 'bg-[#00008B] text-white shadow-lg shadow-blue-900/15' : 'text-slate-600 hover:bg-slate-100 hover:text-[#00008B]' }}"><span class="flex h-9 w-9 items-center justify-center rounded-lg {{ request()->routeIs('perusahaan.*') ? 'bg-white/15' : 'bg-slate-100 group-hover:bg-blue-50' }}"><svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16M3 21h18M9 7h1m4 0h1M9 11h1m4 0h1M9 15h1m4 0h1" />
                            </svg></span><span>Perusahaan</span></a>
                    <a data-sidebar-link href="{{ route('dokter.index') }}" class="group flex items-center gap-3 rounded-xl px-3 py-2.5 font-semibold transition-colors {{ request()->routeIs('dokter.*') ? 'bg-[#00008B] text-white shadow-lg shadow-blue-900/15' : 'text-slate-600 hover:bg-slate-100 hover:text-[#00008B]' }}"><span class="flex h-9 w-9 items-center justify-center rounded-lg {{ request()->routeIs('dokter.*') ? 'bg-white/15' : 'bg-slate-100 group-hover:bg-blue-50' }}"><svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v8m-4-4h8M5 4h14v16H5z" />
                            </svg></span><span>Dokter</span></a>
                    @endcan
                </div>
                @endcanany

                @can('manage-mcu')
                <div class="space-y-1.5">
                    <p class="px-3 pb-1 text-[10px] font-extrabold uppercase tracking-[0.18em] text-slate-400">Sistem
                    </p><a data-sidebar-link href="{{ route('audits.index') }}" class="group flex items-center gap-3 rounded-xl px-3 py-2.5 font-semibold transition-colors {{ request()->routeIs('audits.*') ? 'bg-[#00008B] text-white shadow-lg shadow-blue-900/15' : 'text-slate-600 hover:bg-slate-100 hover:text-[#00008B]' }}"><span class="flex h-9 w-9 items-center justify-center rounded-lg {{ request()->routeIs('audits.*') ? 'bg-white/15' : 'bg-slate-100 group-hover:bg-blue-50' }}"><svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6M7 3h6l4 4v14H7z" />
                            </svg></span><span>Audit Aktivitas</span></a>
                </div>
                @endcan
            </nav>

            <div class="shrink-0 border-t border-slate-100 p-4">
                <p class="mt-3 text-center text-[10px] text-slate-400">&copy; {{ now()->year }} PT Nayaka Era Husada
                </p>
            </div>
        </aside>
        <div id="sidebar-overlay" class="fixed inset-0 z-30 hidden bg-slate-900/50 lg:hidden"></div>
        <div class="mt-1.5 flex min-w-0 flex-1 flex-col overflow-x-hidden">
            <header class="flex h-20 items-center justify-between border-b border-slate-200 bg-white px-6 lg:px-8">
                <div class="flex items-center gap-4"><button type="button" id="open-sidebar" class="rounded-lg p-2 text-slate-600 hover:bg-slate-100 lg:hidden" aria-label="Buka menu"><svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        </svg></button>
                    <div>
                        <h1 class="text-xl font-bold text-slate-800">@yield('title', 'Dashboard Utama')</h1>
                        <p class="text-xs text-slate-400">Sistem operasional Medical Check Up</p>
                    </div>
                </div>
                <details class="relative">
                    <summary class="flex cursor-pointer list-none items-center gap-3 rounded-xl p-1.5 transition hover:bg-slate-100">
                        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-[#00008B] font-bold text-white shadow-md">{{ strtoupper(substr(auth()->user()->name, 0, 2)) }}</span><span class="hidden text-left sm:block"><strong class="block text-sm text-slate-800">{{ auth()->user()->name }}</strong><small class="capitalize text-slate-500">{{ auth()->user()->role }}</small></span>
                    </summary>
                    <div class="absolute right-0 z-50 mt-2 w-56 rounded-2xl border border-slate-100 bg-white py-2 shadow-xl">
                        <div class="border-b border-slate-100 px-4 py-3">
                            <p class="text-sm font-bold">{{ auth()->user()->name }}</p>
                            <p class="truncate text-xs text-slate-500">{{ auth()->user()->email }}</p>
                        </div>
                        <form action="{{ route('logout') }}" method="POST">@csrf<button class="w-full px-4 py-3 text-left text-sm font-medium text-red-600 hover:bg-red-50">Keluar</button>
                        </form>
                    </div>
                </details>
            </header>
            <main class="space-y-8 p-6 lg:p-8">
                @if (session('success'))
                <div role="status" class="rounded-xl border border-emerald-100 bg-emerald-50 p-4 text-sm font-medium text-emerald-700">
                    {{ session('success') }}</div>
                @endif
                @if ($errors->any())
                <div role="alert" class="rounded-xl border border-red-100 bg-red-50 p-4 text-sm text-red-700">
                    <ul class="list-inside list-disc">
                        @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
                @endif
                @yield('content')
            </main>
        </div>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebar-overlay');
            const toggleSidebar = (open) => {
                sidebar.classList.toggle('-translate-x-full', !open);
                overlay.classList.toggle('hidden', !open);
                document.body.classList.toggle('overflow-hidden', open && window.innerWidth < 1024)
            };
            document.getElementById('open-sidebar') ? .addEventListener('click', () => toggleSidebar(true));
            document.getElementById('close-sidebar') ? .addEventListener('click', () => toggleSidebar(false));
            overlay ? .addEventListener('click', () => toggleSidebar(false));
            document.querySelectorAll('[data-sidebar-link]').forEach(link => link.addEventListener('click', () =>
                toggleSidebar(false)));
        });

    </script>
</body>

</html>
