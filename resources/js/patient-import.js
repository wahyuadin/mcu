export function setupPatientImport(dialog, root = document) {
    const form = dialog.querySelector('[data-patient-import-form]');
    const file = form.querySelector('[data-patient-import-file]');
    const save = form.querySelector('[data-patient-import-save]');
    const status = dialog.querySelector('[data-patient-import-status]');
    const preview = dialog.querySelector('[data-patient-import-preview]');
    const confirm = dialog.querySelector('[data-patient-import-confirm]');
    const mapSave = dialog.querySelector('[data-patient-import-map-save]');
    const mapping = dialog.querySelector('[data-patient-import-mapping]');
    const filter = dialog.querySelector('[data-patient-import-filter]');
    const search = dialog.querySelector('[data-patient-import-search]');
    const report = dialog.querySelector('[data-patient-import-report]');
    const drop = dialog.querySelector('[data-patient-import-drop]');
    const alert = dialog.querySelector('[data-patient-import-alert]');
    let batch;
    let mappingDirty = false;
    const closers = dialog.querySelectorAll('[data-patient-import-close]');
    let trigger;
    let animation;
    let closing = false;
    let submitting = false;
    const canConfirm = () => batch && !mappingDirty && batch.status !== 'complete' && batch.stats.failed === 0 && batch.stats.ready > 0 && batch.can_confirm !== false;

    const fade = (from, to, exiting = false) => {
        animation?.cancel();
        const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        const easing = getComputedStyle(dialog).getPropertyValue('--ease-out').trim() || 'cubic-bezier(0.23, 1, 0.32, 1)';
        animation = dialog.animate?.([{ opacity: from }, { opacity: to }], { duration: reduced ? 125 : (exiting ? 200 : 220), easing, fill: 'both' });
        return animation?.finished.catch(() => {}) ?? Promise.resolve();
    };
    const open = (button) => {
        if (dialog.open || closing) return;
        trigger = button;
        dialog.showModal();
        fade(0, 1);
    };
    const close = async () => {
        if (!dialog.open || closing || submitting) return;
        closing = true;
        dialog.dataset.closing = '';
        await fade(Number(getComputedStyle(dialog).opacity), 0, true);
        dialog.close();
        animation?.cancel();
        delete dialog.dataset.closing;
        closing = false;
        trigger?.focus();
    };
    const triggers = root.querySelectorAll('[data-patient-import-open]');
    triggers.forEach((button) => button.addEventListener('click', () => open(button)));
    closers.forEach((button) => button.addEventListener('click', close));
    dialog.addEventListener('cancel', (event) => { event.preventDefault(); close(); });
    dialog.addEventListener('click', (event) => {
        if (event.target !== dialog) return;
        const bounds = dialog.getBoundingClientRect();
        if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) close();
    });
    const selectionChanged = () => {
        const selected = file.files?.[0];
        const invalid = selected && (!selected.name.toLowerCase().endsWith('.xlsx') || selected.size > 5 * 1024 * 1024);
        file.setCustomValidity(invalid ? 'Pilih file XLSX dengan ukuran maksimal 5 MB.' : '');
        status.textContent = invalid ? 'Pilih file XLSX dengan ukuran maksimal 5 MB.' : (selected ? `File dipilih: ${selected.name}` : '');
        batch = null;
        mappingDirty = false;
        preview.hidden = true;
    };
    file.addEventListener('change', selectionChanged);
    ['dragenter', 'dragover'].forEach((name) => drop.addEventListener(name, (event) => {
        event.preventDefault();
        if (!submitting) drop.classList.add('border-blue-500', 'bg-blue-50');
    }));
    ['dragleave', 'drop'].forEach((name) => drop.addEventListener(name, (event) => {
        event.preventDefault();
        drop.classList.remove('border-blue-500', 'bg-blue-50');
    }));
    drop.addEventListener('drop', (event) => {
        event.preventDefault();
        if (submitting) return;
        if (event.dataTransfer.files.length !== 1) { status.textContent = 'Pilih satu file XLSX.'; return; }
        file.files = event.dataTransfer.files;
        selectionChanged();
    });
    const busy = (value) => {
        submitting = value;
        [save, confirm, mapSave, file, ...closers, ...mapping.querySelectorAll('select')].forEach((element) => { element.disabled = value; });
        save.textContent = value ? 'Mohon tunggu…' : 'Upload Data';
    };
    const request = async (url, method, body) => {
        const headers = { Accept: 'application/json', 'X-CSRF-TOKEN': form.querySelector('[name="_token"]').value };
        if (!(body instanceof FormData) && body) { headers['Content-Type'] = 'application/json'; body = JSON.stringify(body); }
        const response = await fetch(url, { method, headers, body, credentials: 'same-origin' });
        const data = await response.json();
        if (!response.ok && !data.id) throw new Error(Object.values(data.errors ?? {}).flat().join(' ') || data.message || 'Permintaan gagal. Silakan coba kembali.');
        return data;
    };
    const text = (tag, value, className = '') => {
        const element = document.createElement(tag);
        element.textContent = value;
        element.className = className;
        return element;
    };
    const renderRows = () => {
        const body = dialog.querySelector('[data-patient-import-rows]');
        body.replaceChildren();
        const rows = filterImportRows(batch.rows, filter.value, search.value);
        rows.forEach((row) => {
            const tr = document.createElement('tr');
            tr.append(text('td', row.number || '—', 'p-3 align-top'));
            const patient = text('td', row.data.nama_karyawan || '—', 'max-w-64 break-words p-3 align-top font-semibold');
            patient.append(text('p', [row.data.nik, row.data.nip, row.data.jenis_kelamin, row.data.tanggal_lahir].filter(Boolean).join(' · '), 'mt-1 text-xs font-normal text-slate-500'));
            const detail = document.createElement('details');
            detail.className = 'mt-2 text-xs font-normal text-slate-500';
            detail.append(text('summary', 'Detail data', 'cursor-pointer'));
            Object.entries(row.data).forEach(([field, value]) => { detail.append(text('p', `${field}: ${value || '—'}`, 'mt-1 break-words')); });
            patient.append(detail);
            tr.append(patient);
            const placement = text('td', row.visit || row.data.kunjungan_id || '—', 'max-w-72 break-words p-3 align-top');
            placement.append(text('p', row.package || row.data.paket_id || '—', 'mt-1 text-xs text-slate-500'));
            tr.append(placement);
            const result = text('td', ({ ready: 'Valid', failed: 'Bermasalah', excluded: 'Tidak diikutkan · kapasitas penuh', succeeded: 'Berhasil', existing: 'Sudah diimport sebelumnya' })[row.status], 'max-w-96 break-words p-3 align-top');
            result.append(text('p', row.nomor_mcu || row.errors.join(' '), `mt-1 text-xs ${row.status === 'failed' ? 'text-red-700' : row.status === 'excluded' ? 'text-amber-800' : 'text-emerald-700'}`));
            tr.append(result);
            body.append(tr);
        });
        if (!rows.length) { const tr = document.createElement('tr'); const cell = text('td', 'Tidak ada baris sesuai filter.', 'p-6 text-center text-slate-500'); cell.colSpan = 4; tr.append(cell); body.append(tr); }
    };
    const render = (data) => {
        batch = data;
        mappingDirty = false;
        preview.hidden = false;
        const complete = batch.status === 'complete';
        dialog.querySelector('[data-patient-import-results]').hidden = !complete;
        dialog.querySelector('[data-patient-import-heading]').textContent = complete ? 'Hasil import' : 'Crosscheck data';
        const s = batch.stats;
        dialog.querySelector('[data-patient-import-summary]').textContent = complete
            ? `${s.succeeded} pasien berhasil · ${s.failed} gagal · ${s.excluded ?? 0} tidak diikutkan · ${s.existing} sudah diimport sebelumnya. ${batch.file_error || 'Baris berhasil tidak perlu diupload ulang.'}`
            : `${s.total} baris · ${s.ready} valid · ${s.failed} bermasalah · ${s.excluded ?? 0} tidak diikutkan karena kapasitas · ${s.existing} sudah diimport. Belum ada pasien baru disimpan.`;
        alert.hidden = s.failed === 0;
        confirm.hidden = complete;
        confirm.disabled = !canConfirm();
        confirm.textContent = `Import ${s.ready} pasien valid`;
        dialog.querySelector('[data-patient-import-mapping-panel]').hidden = complete;
        mapping.replaceChildren();
        Object.entries(batch.mapping).forEach(([field, header]) => {
            const required = (batch.required_fields ?? []).includes(field);
            const label = text('label', `${field} · ${required ? 'Wajib' : 'Opsional'}`, 'grid gap-2 text-xs font-semibold');
            const select = document.createElement('select');
            select.className = 'min-h-11 min-w-0 rounded-xl border border-slate-300 bg-white px-3 text-sm';
            select.dataset.field = field;
            select.required = required;
            const empty = text('option', required ? 'Pilih kolom sumber (wajib)' : 'Tidak dipetakan (opsional)'); empty.value = ''; select.append(empty);
            batch.headers.forEach((name) => { const option = text('option', name); option.value = name; select.append(option); });
            select.value = header ?? '';
            select.addEventListener('change', () => {
                mappingDirty = true;
                confirm.disabled = true;
                status.textContent = 'Mapping belum diterapkan. Klik Terapkan mapping untuk memperbarui crosscheck.';
            });
            label.append(select); mapping.append(label);
        });
        report.hidden = !batch.report_url;
        if (batch.report_url) report.href = batch.report_url;
        renderRows();
    };
    const run = async (operation) => {
        if (submitting || closing) return;
        busy(true);
        status.textContent = 'Mohon tunggu. Jangan tutup halaman sampai proses selesai.';
        try {
            const data = await operation();
            render(data);
            status.textContent = data.status === 'complete' ? 'Proses selesai. Unduh laporan untuk melihat rincian.' : 'Periksa penempatan dan baris bermasalah sebelum import.';
            if (data.report_url && data.stats.failed > 0) {
                const link = document.createElement('a'); link.href = data.report_url; link.download = ''; document.body.append(link); link.click(); link.remove();
                status.textContent += ' Jika unduhan tidak muncul, gunakan tombol Unduh hasil import .xlsx.';
            }
        } catch (error) { status.textContent = error.message || 'Koneksi gagal. Coba kembali; baris berhasil tidak akan dibuat ulang.'; }
        finally { busy(false); confirm.disabled = !canConfirm(); }
    };
    form.addEventListener('submit', (event) => {
        event.preventDefault();
        if (submitting || closing) return;
        if (!form.reportValidity()) return;
        const payload = new FormData(form);
        run(() => request(form.action, 'POST', payload));
    });
    confirm.addEventListener('click', () => { if (canConfirm()) run(() => request(batch.confirm_url, 'POST', { version: batch.version })); });
    mapSave.addEventListener('click', () => {
        if (!batch) return;
        const values = Object.fromEntries([...mapping.querySelectorAll('select')].map((select) => [select.dataset.field, select.value || null]));
        run(() => request(batch.mapping_url, 'PATCH', { mapping: values, version: batch.version }));
    });
    filter.addEventListener('change', () => { if (batch) renderRows(); });
    search.addEventListener('input', () => { if (batch) renderRows(); });
    window.addEventListener('pageshow', () => {
        busy(false);
        confirm.disabled = !canConfirm();
    });
    if (dialog.hasAttribute('data-open-on-load')) open(triggers[0]);
}

export function filterImportRows(rows, filter = 'all', query = '') {
    const needle = query.trim().toLocaleLowerCase('id');
    return rows.filter((row) => (filter === 'all' || (filter === 'valid' ? ['ready', 'succeeded', 'existing'].includes(row.status) : row.status === filter))
        && (!needle || [row.number, ...Object.values(row.data), row.visit, row.package, row.nomor_mcu, ...row.errors].join(' ').toLocaleLowerCase('id').includes(needle)));
}

if (typeof document !== 'undefined') {
    document.querySelectorAll('[data-patient-import-modal]').forEach((dialog) => setupPatientImport(dialog));
}
