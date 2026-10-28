const form = document.querySelector('[data-package-builder]');
const select = form?.querySelector('[data-package-template-select]');
if (select) {
    const button = form.querySelector('[data-package-template-apply]');
    const status = form.querySelector('[data-package-template-status]');
    const signature = () => JSON.stringify([...new FormData(form)].filter(([key]) => key !== 'rencana_kunjungan_id'));
    const hasInitialData = form.elements.namedItem('nama_paket').value || form.elements.namedItem('deskripsi').value || form.querySelector('input[type="checkbox"]:checked');
    let baseline = hasInitialData ? null : signature();
    let busy = false;
    button.addEventListener('click', async () => {
        const url = select.selectedOptions[0]?.dataset.url;
        if (!url || busy) return;
        if (signature() !== baseline && !window.confirm('Form sudah diubah. Ganti isian dengan template paket yang dipilih?')) return;
        busy = true;
        button.disabled = true;
        status.textContent = 'Memuat template…';
        const before = signature();
        try {
            const response = await fetch(url, { headers: { Accept: 'application/json' }, cache: 'no-store' });
            if (!response.ok || !response.headers.get('content-type')?.includes('application/json')) throw new Error('Template gagal dimuat. Periksa koneksi dan sesi login.');
            const data = await response.json();
            if (signature() !== before && !window.confirm('Ada isian baru saat template dimuat. Tetap ganti dengan template?')) return;
            form.elements.namedItem('nama_paket').value = data.nama_paket;
            form.elements.namedItem('deskripsi').value = data.deskripsi ?? '';
            form.querySelectorAll('input[name="master_items[]"], input[name="items[]"]').forEach((input) => {
                input.checked = (input.name === 'master_items[]' ? data.master_items : data.items).includes(input.value);
            });
            form.dispatchEvent(new Event('change', { bubbles: true }));
            baseline = signature();
            status.textContent = data.unavailable.length ? `Template diterapkan. Item tidak tersedia/nonaktif dilewati: ${data.unavailable.join(', ')}.` : 'Template diterapkan. Sesuaikan isian lalu simpan sebagai paket baru.';
        } catch (error) { status.textContent = error.message; }
        finally { busy = false; button.disabled = false; }
    });
}
