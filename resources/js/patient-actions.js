const dialog = document.querySelector('[data-patient-delete-modal]');
if (dialog) {
    const form = dialog.querySelector('form');
    const cancel = dialog.querySelector('[data-patient-delete-cancel]');
    const confirm = dialog.querySelector('[data-patient-delete-confirm]');
    const error = dialog.querySelector('[data-patient-delete-error]');
    let trigger, submitting = false;
    const open = (button) => {
        if (submitting || button.disabled) return;
        trigger = button;
        form.action = button.dataset.url;
        if (error) error.textContent = '';
        dialog.querySelector('[data-patient-delete-name]').textContent = button.dataset.name;
        dialog.showModal();
        cancel.focus();
    };
    if (dialog.dataset.realtime === 'true') {
        document.addEventListener('click', (event) => {
            const button = event.target.closest('[data-patient-delete]');
            if (button) open(button);
        });
    } else {
        document.querySelectorAll('[data-patient-delete]').forEach((button) => button.addEventListener('click', () => open(button)));
    }
    const close = () => { if (!submitting) { dialog.close(); trigger?.focus(); } };
    cancel.addEventListener('click', close);
    dialog.addEventListener('cancel', (event) => { event.preventDefault(); close(); });
    form.addEventListener('submit', async (event) => {
        if (submitting) { event.preventDefault(); return; }
        const realtime = dialog.dataset.realtime === 'true';
        if (realtime) event.preventDefault();
        const body = realtime ? new FormData(form) : null;
        submitting = true;
        cancel.disabled = true;
        confirm.disabled = true;
        confirm.textContent = 'Menghapus…';
        if (!realtime) return;
        if (error) error.textContent = '';
        const request = new AbortController();
        const timeout = window.setTimeout(() => request.abort(), 30000);
        try {
            const response = await fetch(form.action, { method: 'POST', body, signal: request.signal, headers: { Accept: 'application/json' } });
            if (!response.headers.get('content-type')?.includes('application/json')) throw new Error('Sesi berakhir. Muat ulang halaman dan coba lagi.');
            const data = await response.json();
            if (!response.ok) throw new Error(Object.values(data.errors ?? {}).flat().join(' ') || data.message || 'Pasien gagal dihapus.');
            submitting = false;
            close();
            document.dispatchEvent(new CustomEvent('mcu:patient-updated', { detail: { message: data.message, id: trigger?.dataset.patientId } }));
        } catch (exception) {
            if (error) { error.textContent = exception.name === 'AbortError' ? 'Pemuatan terlalu lama. Periksa daftar pasien sebelum mencoba lagi.' : exception.message; error.focus(); }
        } finally {
            window.clearTimeout(timeout);
            submitting = false;
            cancel.disabled = false;
            confirm.disabled = false;
            confirm.textContent = 'Hapus pasien';
        }
    });
    window.addEventListener('pageshow', () => {
        submitting = false;
        cancel.disabled = false;
        confirm.disabled = false;
        confirm.textContent = 'Hapus pasien';
    });
}
