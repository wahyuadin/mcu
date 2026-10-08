@extends('layouts.mcu')
@section('title', 'Audit Aktivitas')
@section('content')
<section class="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-md shadow-slate-200/50">
    <div class="border-b border-slate-100 p-6"><h2 class="text-lg font-bold text-slate-800">Riwayat Perubahan Data</h2><p class="text-xs text-slate-400">Pencatatan otomatis create, update, delete, dan restore.</p></div>
    <div class="overflow-x-auto"><table class="w-full text-left text-sm text-slate-600"><thead class="bg-slate-50 text-xs font-semibold uppercase text-slate-400"><tr><th class="px-6 py-4">Waktu</th><th class="px-6 py-4">Pengguna</th><th class="px-6 py-4">Aktivitas</th><th class="px-6 py-4">Data</th><th class="px-6 py-4">IP</th></tr></thead><tbody class="divide-y divide-slate-100">@forelse($audits as $audit)<tr class="hover:bg-slate-50/50"><td class="whitespace-nowrap px-6 py-4">{{ $audit->created_at?->format('d M Y H:i') }}</td><td class="px-6 py-4 font-medium text-slate-800">{{ $audit->user?->name ?? 'Sistem' }}</td><td class="px-6 py-4"><span class="rounded-md bg-blue-50 px-2 py-1 text-xs font-semibold uppercase text-[#00008B]">{{ $audit->event }}</span></td><td class="px-6 py-4">{{ class_basename($audit->auditable_type) }}<span class="block max-w-48 truncate text-xs text-slate-400">{{ $audit->auditable_id }}</span></td><td class="px-6 py-4">{{ $audit->ip_address ?? '—' }}</td></tr>@empty<tr><td colspan="5" class="px-6 py-12 text-center text-slate-400">Belum ada aktivitas audit.</td></tr>@endforelse</tbody></table></div>
    <div class="border-t border-slate-100 p-5">{{ $audits->links() }}</div>
</section>
@endsection
