<div class="flex flex-wrap justify-end gap-2">
    @isset($manageUrl)
        <a href="{{ $manageUrl }}" aria-label="Kelola item {{ $name }}" title="Kelola item" class="inline-flex h-11 w-11 items-center justify-center rounded-xl border border-slate-200 bg-white text-[#00008B] hover:bg-slate-50"><svg aria-hidden="true" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-width="2" stroke-linecap="round" d="M9 6h12M9 12h12M9 18h12M3 6h1M3 12h1M3 18h1"/></svg></a>
    @endisset
    @isset($detailUrl)
        <a href="{{ $detailUrl }}" aria-label="Detail {{ $name }}" class="inline-flex min-h-11 items-center gap-2 whitespace-nowrap rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-50">
            <svg aria-hidden="true" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3" stroke-width="2"/></svg>Detail
        </a>
    @endisset
    <a href="{{ $editUrl }}" aria-label="Ubah {{ $name }}" title="Ubah" class="inline-flex min-h-11 items-center justify-center gap-2 whitespace-nowrap rounded-xl border border-blue-200 bg-blue-50 {{ ($compact ?? false) ? 'w-11' : 'px-3 py-2' }} text-xs font-semibold text-[#00008B] hover:bg-blue-100">
        <svg aria-hidden="true" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m16 4 4 4M4 20l4-1L20 7a2.8 2.8 0 0 0-4-4L4 15v5Z"/></svg><span @class(['sr-only' => $compact ?? false])>Ubah</span>
    </a>
    <form method="POST" action="{{ $deleteUrl }}" onsubmit="return confirm('Hapus data ini? Paket dan hasil pemeriksaan tersimpan tidak berubah.')">
        @csrf @method('DELETE')
        <button type="submit" aria-label="Hapus {{ $name }}" title="Hapus" class="inline-flex min-h-11 items-center justify-center gap-2 whitespace-nowrap rounded-xl border border-red-200 bg-red-50 {{ ($compact ?? false) ? 'w-11' : 'px-3 py-2' }} text-xs font-semibold text-red-700 hover:bg-red-100">
            <svg aria-hidden="true" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15M10 10v7m4-7v7"/></svg><span @class(['sr-only' => $compact ?? false])>Hapus</span>
        </button>
    </form>
</div>
