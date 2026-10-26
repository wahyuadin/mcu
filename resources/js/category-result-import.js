export function validateResultFile(file) {
    if (!file || !file.name.toLowerCase().endsWith('.xlsx')) return 'Pilih satu file XLSX sesuai template.';
    if (file.size > 5 * 1024 * 1024) return 'Ukuran file maksimal 5 MB.';
    return null;
}

export function initializeCategoryImport(dialog, triggers, window, document) {
    const find = (name) => dialog.querySelector(`[data-${name}]`);
    const form = find('result-import-form'), category = find('import-category'), fileInput = find('result-import-file');
    const upload = find('result-upload'), confirm = find('result-confirm'), status = find('result-import-status');
    const template = find('result-template'), preview = find('result-preview'), rows = find('result-preview-rows');
    const progressWrap = find('result-progress-wrap'), progress = find('result-progress'), progressText = find('result-progress-text');
    const closes = [...dialog.querySelectorAll('[data-result-import-close]')];
    let options = [], selectedFile, batch, busy = false, closing = false, trigger, animation, request, page = 0, fetching;
    const csrf = () => form.querySelector('[name="_token"]').value;
    const selected = () => options.find((option) => option.id === category.value);
    const setBusy = (value) => {
        busy = value;
        closes.forEach((button) => { button.disabled = value; });
        category.disabled = value || options.length === 0;
        fileInput.disabled = value || !selected();
        upload.disabled = value || !selectedFile || !selected();
        confirm.disabled = value || !batch || batch.status === 'complete' || batch.stats.failed > 0 || batch.stats.ready === 0;
        dialog.setAttribute('aria-busy', String(value));
    };
    const fade = (from, to, exiting = false) => {
        animation?.cancel();
        const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        const easing = window.getComputedStyle(dialog).getPropertyValue('--ease-out').trim() || 'cubic-bezier(0.23, 1, 0.32, 1)';
        animation = dialog.animate?.([{ opacity: from }, { opacity: to }], { duration: reduced ? 125 : (exiting ? 200 : 220), easing, fill: 'both' });
        return animation?.finished.catch(() => {}) ?? Promise.resolve();
    };
    const close = async () => {
        if (!dialog.open || busy || closing) return;
        closing = true;
        fetching?.abort();
        await fade(window.getComputedStyle(dialog).opacity, 0, true);
        dialog.close(); animation?.cancel(); closing = false; trigger?.focus();
    };
    const resetPreview = () => { batch = null; page = 0; preview.hidden = true; rows.replaceChildren(); };
    const renderRows = () => {
        rows.replaceChildren();
        const visible = batch.rows.slice(page * 50, (page + 1) * 50);
        for (const item of visible) {
            const row = document.createElement('tr');
            const identity = document.createElement('td'); identity.className = 'max-w-64 break-words p-3 align-top';
            identity.textContent = `${item.number} · ${item.name}\n${item.nomor_mcu}`;
            const state = document.createElement('td'); state.className = 'p-3 align-top font-semibold';
            state.textContent = ({ ready: 'Siap', failed: 'Gagal validasi', skipped: 'Dilewati', succeeded: 'Tersimpan' })[item.status] || item.status;
            const detail = document.createElement('td'); detail.className = 'max-w-xl whitespace-pre-line break-words p-3 align-top';
            const lines = [...item.errors];
            if (item.retained) lines.push(`${item.retained} nilai lama dipertahankan.`);
            for (const change of item.changes || []) lines.push(`${change.label}: ${change.value}`);
            detail.textContent = lines.join('\n') || (item.status === 'succeeded' ? 'Hasil tersimpan sebagai draft.' : 'Tidak ada nilai baru.');
            row.append(identity, state, detail); rows.append(row);
        }
        const totalPages = Math.max(1, Math.ceil(batch.rows.length / 50));
        find('result-page').textContent = `Halaman ${page + 1} dari ${totalPages}`;
        find('result-prev').disabled = page === 0;
        find('result-next').disabled = page >= totalPages - 1;
    };
    const showBatch = (data) => {
        batch = data; page = 0; preview.hidden = false;
        find('result-summary').textContent = `${data.stats.total} baris · ${data.stats.ready} siap · ${data.stats.failed} gagal · ${data.stats.skipped} dilewati · ${data.stats.succeeded} tersimpan`;
        find('result-report').href = data.report_url;
        renderRows();
    };
    const acceptFile = (file) => {
        if (busy || !selected()) return;
        resetPreview();
        const error = validateResultFile(file);
        selectedFile = error ? null : file;
        status.textContent = error || 'File siap diupload. Hasil belum disimpan.';
        find('result-file-name').textContent = selectedFile ? `${file.name} · ${(file.size / 1024).toFixed(1)} KB` : 'Belum ada file dipilih';
        progressWrap.hidden = true;
        if (error) fileInput.value = '';
        setBusy(false);
    };
    const readError = (data, fallback) => data?.errors ? Object.values(data.errors).flat().join(' ') : fallback;
    const requestJson = (url, body, uploading) => new Promise((resolve, reject) => {
        const xhr = new window.XMLHttpRequest(); request = xhr;
        xhr.open('POST', url); xhr.timeout = 120000;
        xhr.setRequestHeader('Accept', 'application/json'); xhr.setRequestHeader('X-CSRF-TOKEN', csrf());
        if (!uploading) xhr.setRequestHeader('Content-Type', 'application/json');
        if (uploading) {
            xhr.upload.onprogress = (event) => {
                if (!event.lengthComputable) { progress.removeAttribute('value'); return; }
                const value = Math.round(event.loaded / event.total * 100); progress.value = value;
                progressText.textContent = value === 100 ? 'File diterima. Memvalidasi data…' : `Mengupload ${value}%`;
                if (value === 100) progress.removeAttribute('value');
            };
        }
        xhr.onload = () => {
            let data;
            try { data = JSON.parse(xhr.responseText); } catch { reject(new Error('Respons tidak valid. Sesi mungkin berakhir; muat ulang halaman lalu coba lagi.')); return; }
            if (xhr.status < 200 || xhr.status >= 300) {
                reject(new Error(readError(data, ({ 403: 'Anda tidak memiliki izin atau kategori/kunjungan terkunci.', 410: 'Preview kedaluwarsa. Upload ulang.', 419: 'Sesi berakhir. Muat ulang halaman.', 429: 'Terlalu banyak permintaan. Tunggu sebentar lalu coba lagi.' })[xhr.status] || 'Permintaan gagal. Periksa file dan coba ulang.')));
            } else resolve(data);
        };
        xhr.onerror = () => reject(new Error('Koneksi gagal. File tetap dipilih; silakan coba ulang.'));
        xhr.ontimeout = () => reject(new Error('Permintaan terlalu lama. Periksa koneksi lalu coba ulang.'));
        xhr.onabort = () => reject(new Error('Permintaan dibatalkan.'));
        xhr.send(body);
    });
    const openImport = async (button) => {
        if (dialog.open || busy || closing) return;
        trigger = button; options = []; selectedFile = null; fileInput.value = ''; resetPreview();
        category.replaceChildren(new window.Option('Memuat kategori…', '')); template.hidden = true; template.removeAttribute('href');
        find('result-file-name').textContent = 'Belum ada file dipilih'; find('import-visit').textContent = button.dataset.visitName;
        progressWrap.hidden = true; status.textContent = 'Memuat kategori yang dapat diimport…';
        dialog.showModal(); fade(0, 1); setBusy(false); fetching = new window.AbortController();
        const current = fetching;
        try {
            const response = await window.fetch(button.dataset.categoriesUrl, { signal: current.signal, cache: 'no-store', headers: { Accept: 'application/json' } });
            if (!response.ok || response.redirected) throw new Error('Kategori tidak dapat dimuat. Periksa izin dan status kunjungan.');
            const data = await response.json();
            if (current !== fetching || !dialog.open || closing) return;
            options = data.categories.filter((option) => !button.dataset.categoryId || option.id === button.dataset.categoryId); category.replaceChildren(new window.Option(options.length ? 'Pilih kategori pemeriksaan' : 'Tidak ada kategori yang tersedia', ''));
            for (const option of options) category.append(new window.Option(option.name, option.id));
            status.textContent = options.length ? 'Pilih kategori lalu download template terbaru.' : 'Aktifkan Import Data di master kategori dan pastikan item terikat pada paket peserta.';
            if (button.dataset.categoryId && options.length === 1) {
                category.value = options[0].id;
                category.hidden = true;
                template.hidden = false;
                template.href = options[0].template_url;
                status.textContent = `${options[0].name} — template untuk seluruh peserta kunjungan, tanpa mengikuti pencarian atau halaman tabel.`;
            }
            setBusy(false);
        } catch (error) { if (!current.signal.aborted) status.textContent = error.message; }
    };
    if (document.addEventListener) {
        document.addEventListener('click', (event) => {
            const button = event.target.closest('[data-category-import-open]');
            if (button) openImport(button);
        });
    } else {
        for (const button of triggers) button.addEventListener('click', () => openImport(button));
    }
    category.addEventListener('change', () => {
        selectedFile = null; fileInput.value = ''; resetPreview(); progressWrap.hidden = true;
        find('result-file-name').textContent = 'Belum ada file dipilih';
        const option = selected(); template.hidden = !option;
        if (option) template.href = option.template_url; else template.removeAttribute('href');
        status.textContent = option ? 'Download template kategori ini, isi hasil, lalu upload.' : 'Pilih kategori terlebih dahulu.';
        setBusy(false);
    });
    fileInput.addEventListener('change', () => acceptFile(fileInput.files?.[0]));
    const dropzone = find('result-dropzone');
    for (const type of ['dragenter', 'dragover']) dropzone.addEventListener(type, (event) => { event.preventDefault(); if (!busy && selected()) dropzone.classList.add('bg-indigo-50', 'border-indigo-500'); });
    for (const type of ['dragleave', 'drop']) dropzone.addEventListener(type, (event) => { event.preventDefault(); dropzone.classList.remove('bg-indigo-50', 'border-indigo-500'); });
    dropzone.addEventListener('drop', (event) => {
        if (busy || !selected()) return;
        if (event.dataTransfer?.files?.length !== 1) { acceptFile(null); status.textContent = 'Pilih satu file XLSX saja.'; return; }
        acceptFile(event.dataTransfer.files[0]);
    });
    form.addEventListener('submit', async (event) => {
        event.preventDefault(); if (busy || !selectedFile || !selected()) return;
        const body = new window.FormData(); body.append('import_file', selectedFile); body.append('_token', csrf());
        resetPreview(); setBusy(true); progressWrap.hidden = false; progress.value = 0; progressText.textContent = 'Mengupload file…'; status.textContent = 'Upload dan validasi berlangsung. Jangan tutup jendela ini.';
        try { showBatch(await requestJson(selected().upload_url, body, true)); progress.value = 100; progressText.textContent = 'Validasi selesai'; status.textContent = batch.stats.failed ? 'Perbaiki baris gagal di Excel, lalu upload kembali. Belum ada hasil disimpan.' : 'Periksa preview. Tekan konfirmasi untuk menyimpan draft.'; }
        catch (error) { status.textContent = error.message; progressText.textContent = 'Upload belum selesai'; }
        finally { request = null; setBusy(false); }
    });
    confirm.addEventListener('click', async () => {
        if (busy || !batch || batch.stats.failed || !batch.stats.ready || batch.status === 'complete') return;
        setBusy(true); status.textContent = 'Menyimpan hasil sebagai draft…';
        try { showBatch(await requestJson(batch.confirm_url, JSON.stringify({ digest: batch.digest, confirmed: 1 }), false)); status.textContent = 'Import selesai. Hasil tersimpan sebagai draft untuk ditinjau petugas. Muat ulang tab pemeriksaan untuk melihat hasil terbaru sebelum mengedit atau menyimpan isian.'; }
        catch (error) { status.textContent = error.message; }
        finally { request = null; setBusy(false); }
    });
    find('result-prev').addEventListener('click', () => { if (batch && page > 0) { page--; renderRows(); } });
    find('result-next').addEventListener('click', () => { if (batch && (page + 1) * 50 < batch.rows.length) { page++; renderRows(); } });
    closes.forEach((button) => button.addEventListener('click', close));
    dialog.addEventListener('cancel', (event) => { event.preventDefault(); close(); });
    dialog.addEventListener('click', (event) => { if (event.target === dialog && (event.offsetX < 0 || event.offsetY < 0 || event.offsetX > dialog.clientWidth || event.offsetY > dialog.clientHeight)) close(); });
    return { close, acceptFile };
}

if (typeof document !== 'undefined') {
    const dialog = document.querySelector('[data-category-import]');
    if (dialog) initializeCategoryImport(dialog, document.querySelectorAll('[data-category-import-open]'), window, document);
}
