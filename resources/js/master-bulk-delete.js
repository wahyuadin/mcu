export function setupMasterBulkDelete(scope) {
    const form = scope.querySelector('[data-bulk-delete-form]');
    const all = scope.querySelector('[data-bulk-delete-all]');
    const rows = [...scope.querySelectorAll('[data-bulk-delete-row]')];
    const count = scope.querySelector('[data-bulk-delete-count]');
    const submit = scope.querySelector('[data-bulk-delete-submit]');
    let submitting = false;

    const update = () => {
        const selected = rows.filter((row) => row.checked).length;
        count.textContent = `${selected} data terpilih`;
        all.checked = rows.length > 0 && selected === rows.length;
        all.indeterminate = selected > 0 && selected < rows.length;
        all.disabled = submitting || rows.length === 0;
        submit.disabled = submitting || selected === 0;
    };
    all.addEventListener('change', () => {
        if (submitting) return;
        rows.forEach((row) => { row.checked = all.checked; });
        update();
    });
    rows.forEach((row) => row.addEventListener('change', update));
    form.addEventListener('submit', (event) => {
        const selected = rows.filter((row) => row.checked).length;
        if (submitting || !selected || !window.confirm(`Hapus ${selected} data terpilih? Data yang masih digunakan akan dilewati. Penghapusan master tidak mengubah hasil pemeriksaan tersimpan.`)) {
            event.preventDefault();
            return;
        }
        submitting = true;
        submit.textContent = 'Menghapus…';
        // Keep selected inputs enabled so the browser includes their IDs in the form submission.
        update();
    });
    window.addEventListener('pageshow', () => {
        submitting = false;
        rows.forEach((row) => { row.checked = false; });
        submit.textContent = 'Hapus terpilih';
        update();
    });
    update();
}

if (typeof document !== 'undefined') {
    document.querySelectorAll('[data-bulk-delete]').forEach(setupMasterBulkDelete);
}
