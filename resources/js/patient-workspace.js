export function setupPatientWorkspace(document, window) {
    const root = document.querySelector('[data-patient-workspace]');
    if (!root) return;
    const filter = root.querySelector('[data-patient-filter]');
    const search = filter.elements.namedItem('q');
    const skeleton = root.querySelector('[data-loading-skeleton]');
    const status = root.querySelector('[data-patient-workspace-status]');
    const modal = document.querySelector('[data-patient-modal]');
    const body = modal.querySelector('[data-patient-modal-body]');
    const modalSkeleton = modal.querySelector('[data-loading-skeleton]');
    const modalError = modal.querySelector('[data-patient-modal-error]');
    const retry = modal.querySelector('[data-patient-modal-retry]');
    let timer, listController, listSequence = 0, modalController, modalSequence = 0;
    let activeUrl, activeId, activeAction, saving = false, dirty = false, releasePhoto = () => {};
    const results = () => root.querySelector('[data-patient-results]');
    const syncFilters = (url) => {
        ['q', 'perusahaan', 'status', 'sort', 'direction'].forEach((key) => {
            filter.elements.namedItem(key).value = url.searchParams.get(key) ?? ({ sort: 'created_at', direction: 'desc' }[key] ?? '');
        });
    };
    const filterUrl = () => {
        const url = new URL(filter.action, window.location.href);
        for (const [key, value] of new FormData(filter)) if (value) url.searchParams.set(key, value);
        return url;
    };
    const refresh = async (url = new URL(window.location.href), updateHistory = true) => {
        window.clearTimeout(timer);
        listController?.abort();
        const request = listController = new AbortController();
        const id = ++listSequence;
        const timeout = window.setTimeout(() => request.abort(), 30000);
        results().hidden = true;
        results().inert = true;
        skeleton.hidden = false;
        root.setAttribute('aria-busy', 'true');
        status.textContent = '';
        try {
            const response = await window.fetch(url, { signal: request.signal, cache: 'no-store', headers: { Accept: 'text/html' } });
            if (!response.ok || response.redirected) throw new Error('Daftar pasien gagal dimuat. Periksa koneksi atau sesi login lalu coba lagi.');
            const page = new DOMParser().parseFromString(await response.text(), 'text/html');
            if (id !== listSequence) return false;
            const next = page.querySelector('[data-patient-results]');
            if (!next) throw new Error('Respons daftar pasien tidak valid. Coba lagi.');
            if (!next.querySelector('[data-patient-row]') && Number(url.searchParams.get('page')) > 1) {
                url.searchParams.set('page', String(Number(url.searchParams.get('page')) - 1));
                return refresh(url, updateHistory);
            }
            results().replaceWith(next);
            root.querySelector('[data-patient-stats]').replaceWith(page.querySelector('[data-patient-stats]'));
            if (updateHistory) window.history.replaceState({}, '', url);
            syncFilters(url);
            return true;
        } catch (exception) {
            if (id === listSequence) status.textContent = exception.name === 'AbortError' ? 'Pemuatan terlalu lama. Ubah filter untuk mencoba lagi.' : exception.message;
            return false;
        } finally {
            window.clearTimeout(timeout);
            if (id === listSequence) {
                results().hidden = false;
                results().inert = false;
                skeleton.hidden = true;
                root.removeAttribute('aria-busy');
            }
        }
    };
    filter.addEventListener('submit', (event) => { event.preventDefault(); refresh(filterUrl()); });
    const schedule = () => {
        window.clearTimeout(timer);
        listController?.abort();
        listSequence++;
        timer = window.setTimeout(() => refresh(filterUrl()), 300);
    };
    search.addEventListener('input', (event) => { if (!event.isComposing) schedule(); });
    search.addEventListener('compositionend', schedule);
    filter.addEventListener('change', (event) => { if (event.target.tagName === 'SELECT') refresh(filterUrl()); });
    const focusTrigger = () => {
        const trigger = [...root.querySelectorAll('[data-patient-open]')].find((button) => button.dataset.patientId === activeId && button.dataset.patientOpen === activeAction);
        (trigger ?? search).focus({ preventScroll: true });
    };
    const close = () => {
        if (saving || (dirty && !window.confirm('Ada perubahan yang belum disimpan. Tutup modal dan buang perubahan?'))) return;
        modalController?.abort();
        modalSequence++;
        releasePhoto();
        dirty = false;
        modal.close();
        focusTrigger();
    };
    const showError = (message) => { modalError.textContent = message; modalError.hidden = false; modalError.focus(); };
    const mountEdit = () => {
        const form = body.querySelector('[data-patient-edit-form]');
        if (!form) return;
        const input = form.querySelector('[data-patient-photo-input]');
        const preview = form.querySelector('[data-patient-photo-preview]');
        const placeholder = form.querySelector('[data-patient-photo-placeholder]');
        const reset = form.querySelector('[data-patient-photo-reset]');
        const feedback = form.querySelector('[data-patient-form-status]');
        const stored = preview.getAttribute('src');
        let objectUrl;
        releasePhoto = () => { if (objectUrl) window.URL.revokeObjectURL(objectUrl); objectUrl = null; };
        const resetPhoto = () => {
            releasePhoto();
            input.value = '';
            preview.src = stored || '';
            preview.hidden = !stored;
            placeholder.hidden = Boolean(stored);
            reset.hidden = true;
        };
        input.addEventListener('change', () => {
            feedback.textContent = '';
            const file = input.files?.[0];
            if (!file) { resetPhoto(); return; }
            if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 5 * 1024 * 1024) {
                resetPhoto();
                feedback.textContent = 'Gunakan JPG, PNG, atau WebP dengan ukuran maksimal 5 MB.';
                feedback.focus();
                return;
            }
            releasePhoto();
            objectUrl = window.URL.createObjectURL(file);
            preview.src = objectUrl;
            preview.hidden = false;
            placeholder.hidden = true;
            reset.hidden = false;
        });
        reset.addEventListener('click', resetPhoto);
        form.addEventListener('input', () => { dirty = true; });
        form.addEventListener('change', () => { dirty = true; });
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (saving) return;
            const payload = new FormData(form);
            saving = true;
            feedback.textContent = '';
            const controls = [...modal.querySelectorAll('input, select, button')];
            controls.forEach((control) => { control.disabled = true; });
            const save = form.querySelector('[data-patient-save]');
            save.textContent = 'Menyimpan…';
            const request = new AbortController();
            const timeout = window.setTimeout(() => request.abort(), 30000);
            try {
                const response = await window.fetch(form.action, { method: 'POST', body: payload, signal: request.signal, headers: { Accept: 'application/json' } });
                if (!response.headers.get('content-type')?.includes('application/json')) throw new Error('Sesi berakhir atau server tidak merespons. Salin isian Anda sebelum memuat ulang halaman.');
                const data = await response.json();
                if (!response.ok) throw new Error(Object.values(data.errors ?? {}).flat().join(' ') || data.message || 'Data pasien gagal disimpan.');
                dirty = false;
                await refresh();
                status.textContent = `${data.message} ${status.textContent}`;
                saving = false;
                close();
            } catch (exception) { feedback.textContent = exception.name === 'AbortError' ? 'Penyimpanan terlalu lama. Periksa koneksi dan data pasien sebelum mencoba lagi.' : exception.message; feedback.focus(); }
            finally {
                window.clearTimeout(timeout);
                saving = false;
                controls.forEach((control) => { control.disabled = false; });
                save.textContent = 'Simpan perubahan';
            }
        });
    };
    const loadModal = async () => {
        modalController?.abort();
        const request = modalController = new AbortController();
        const id = ++modalSequence;
        const timeout = window.setTimeout(() => request.abort(), 30000);
        releasePhoto();
        dirty = false;
        body.replaceChildren();
        modalError.hidden = true;
        retry.hidden = true;
        modalSkeleton.hidden = false;
        body.setAttribute('aria-busy', 'true');
        try {
            const response = await window.fetch(activeUrl, { cache: 'no-store', signal: request.signal, headers: { Accept: 'text/html' } });
            if (!response.ok || response.redirected) throw new Error(response.status === 403 ? 'Data pasien tidak dapat diakses atau kunjungan sudah dikunci.' : 'Data pasien gagal dimuat. Periksa koneksi dan sesi login.');
            const page = new DOMParser().parseFromString(await response.text(), 'text/html');
            if (id !== modalSequence || !modal.open) return;
            const content = page.querySelector('[data-patient-modal-content]');
            if (!content) throw new Error('Respons pasien tidak valid. Silakan coba lagi.');
            body.replaceChildren(content);
            mountEdit();
        } catch (exception) {
            if (id !== modalSequence || !modal.open) return;
            showError(exception.name === 'AbortError' ? 'Pemuatan terlalu lama. Silakan coba lagi.' : exception.message);
            retry.hidden = false;
        } finally {
            window.clearTimeout(timeout);
            if (id === modalSequence) { modalSkeleton.hidden = true; body.removeAttribute('aria-busy'); }
        }
    };
    root.addEventListener('click', (event) => {
        const button = event.target.closest('[data-patient-open]');
        if (button && !button.disabled) {
            activeUrl = button.dataset.url;
            activeId = button.dataset.patientId;
            activeAction = button.dataset.patientOpen;
            modal.querySelector('[data-patient-modal-title]').textContent = activeAction === 'edit' ? 'Edit pasien' : 'Detail pasien';
            modal.showModal();
            loadModal();
            return;
        }
        const link = event.target.closest('a');
        if (!link || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || link.target) return;
        const url = new URL(link.href, window.location.href);
        if (url.origin !== window.location.origin || url.pathname !== new URL(filter.action).pathname) return;
        event.preventDefault();
        syncFilters(url);
        refresh(url);
    });
    modal.addEventListener('click', (event) => { if (event.target.closest('[data-patient-modal-close]')) close(); });
    modal.addEventListener('cancel', (event) => { event.preventDefault(); close(); });
    retry.addEventListener('click', loadModal);
    document.addEventListener('mcu:patient-updated', async (event) => {
        await refresh();
        status.textContent = `${event.detail.message} ${status.textContent}`;
        if (!document.activeElement || document.activeElement === document.body) search.focus({ preventScroll: true });
    });
    window.addEventListener('beforeunload', (event) => { if (dirty && !saving) { event.preventDefault(); event.returnValue = ''; } });
    window.addEventListener('popstate', () => { const url = new URL(window.location.href); syncFilters(url); refresh(url, false); });
}

if (typeof document !== 'undefined') setupPatientWorkspace(document, window);
