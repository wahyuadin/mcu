<section class="report-group {{ $kind === 'columns' ? 'exam-group' : ($kind === 'laboratory' ? 'lab-group' : 'detail-group') }}" data-report-group @if(isset($group['reference_page'])) data-reference-page="{{ $group['reference_page'] }}" @endif>
    @if($group['name'])<h3 class="{{ $kind === 'laboratory' ? 'section-label' : '' }}">{{ $group['name'] }}</h3>@endif
    <table class="{{ $kind === 'columns' ? 'exam-table' : ($kind === 'laboratory' ? 'lab-table' : 'detail-table') }}">
        @if($kind === 'laboratory')
            <colgroup><col class="lab-label"><col class="lab-result"><col class="lab-unit"><col class="lab-reference"><col class="lab-remark"></colgroup>
            <thead><tr>@foreach(['Pemeriksaan', 'Hasil', 'Unit', 'Nilai Normal', 'Keterangan'] as $heading)<th scope="col">{{ $heading }}</th>@endforeach</tr></thead>
        @endif
        <tbody>
            @foreach($group['rows'] as $row)
                @if($row['subheading'] ?? null)<tr class="exam-subheading"><td colspan="2">{{ $row['subheading'] }}</td></tr>@endif
                <tr data-report-row data-item-key="{{ $row['key'] }}" @class(['abnormal' => $row['abnormal'] ?? false, 'impression' => $row['impression'] ?? false])>
                    <td class="item-label">@if(isset($row['number']))<span class="item-number">{{ $row['number'] }}.</span>@endif{{ $row['label'] }}</td>
                    <td class="item-value">{{ $row['value'] }}</td>
                    @if($kind === 'laboratory')<td>{{ $row['unit'] }}</td><td>{{ $row['reference'] }}</td><td>{{ $row['remark'] }}</td>@endif
                </tr>
            @endforeach
        </tbody>
    </table>
</section>
