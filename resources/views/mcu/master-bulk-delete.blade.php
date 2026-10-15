@if(session('bulkDeleteResults'))
    <details open class="m-5 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm">
        <summary class="cursor-pointer font-semibold">Rincian penghapusan</summary>
        <ul class="mt-3 max-h-60 space-y-2 overflow-y-auto">@foreach(session('bulkDeleteResults') as $result)<li class="break-words {{ $result['deleted'] ? 'text-emerald-700' : 'text-red-700' }}"><strong>{{ $result['name'] }}</strong> — {{ $result['reason'] }}</li>@endforeach</ul>
    </details>
@endif
<form id="master-bulk-delete" data-bulk-delete-form method="POST" action="{{ route($bulkRoute) }}" class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 p-5">
    @csrf
    @foreach($bulkFilters as $filter)
        @if(request()->filled($filter))<input type="hidden" name="{{ $filter }}" value="{{ request($filter) }}">@endif
    @endforeach
    <div><p data-bulk-delete-count role="status" aria-live="polite" class="text-sm font-semibold text-slate-700">0 data terpilih</p><p class="mt-1 text-xs text-slate-500">{{ $bulkHelp ?? 'Pilih semua hanya berlaku untuk halaman ini. Data yang masih digunakan akan dilewati.' }}</p></div>
    <button type="submit" data-bulk-delete-submit disabled class="min-h-11 rounded-xl bg-red-700 px-4 py-3 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50">Hapus terpilih</button>
</form>
