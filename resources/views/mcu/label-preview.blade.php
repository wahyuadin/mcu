<div class="overflow-x-auto rounded-xl border border-slate-200 bg-slate-50 p-4">
    <div data-label-preview class="mcu-label">
        <strong data-label-heading></strong>
        <strong data-label-person>{{ $labelPerson }}</strong>
        <span data-label-time>{{ now()->format('d:m:Y H:i:s') }}</span>
        <img data-label-image alt="Barcode label pemeriksaan">
    </div>
</div>
<p data-label-warning role="status" aria-live="polite" class="text-sm text-amber-800"></p>
<template data-label-styles>@include('mcu.label-styles')</template>
