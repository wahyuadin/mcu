<div data-loading-skeleton hidden aria-hidden="true" class="grid gap-5 rounded-2xl border border-slate-200 bg-white p-6">
    <div class="skeleton-block h-6 w-2/5 rounded-lg"></div>
    @foreach(range(1, 4) as $row)
        <div class="grid gap-3 rounded-xl border border-slate-100 p-4">
            <div class="skeleton-block h-4 w-3/5 rounded"></div>
            <div class="skeleton-block h-4 w-2/5 rounded"></div>
            <div class="skeleton-block h-11 w-full rounded-lg"></div>
        </div>
    @endforeach
</div>
