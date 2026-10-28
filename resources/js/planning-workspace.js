export function setupPlanningWorkspace(document, window) {
    const root = document.querySelector('[data-planning-workspace]');
    if (!root) return;
    const filter = root.querySelector('[data-planning-filter]');
    const skeleton = root.querySelector('[data-loading-skeleton]');
    const status = root.querySelector('[data-planning-status]');
    const modal = document.querySelector('[data-planning-modal]');
    const form = modal.querySelector('form');
    const errors = modal.querySelector('[data-planning-errors]');
    let timer, controller, sequence = 0, saving = false, triggerUrl;
    const results = () => root.querySelector('[data-planning-results]');
    const refresh = async (url, history = true) => {
        window.clearTimeout(timer);
        controller?.abort();
        controller = new AbortController();
        const request = controller;
        const id = ++sequence;
        const timeout = window.setTimeout(() => request.abort(), 30000);
        results().hidden = true;
        results().inert = true;
        skeleton.hidden = false;
        root.setAttribute('aria-busy', 'true');
        status.textContent = '';
        try {
            const response = await window.fetch(url, { cache: 'no-store', signal: request.signal, headers: { Accept: 'text/html' } });
            if (!response.ok || response.redirected) throw new Error('Daftar gagal dimuat. Periksa koneksi atau muat ulang halaman untuk login kembali.');
            const page = new DOMParser().parseFromString(await response.text(), 'text/html');
            if (id !== sequence) return;
            const next = page.querySelector('[data-planning-results]');
            if (!next) throw new Error('Daftar tidak tersedia. Silakan coba lagi.');
            results().replaceWith(next);
            ['[data-planning-count]', '[data-planning-stats]'].forEach((selector) => root.querySelector(selector).replaceWith(page.querySelector(selector)));
            if (history) window.history.replaceState({}, '', url);
        } catch (error) {
            if (id === sequence && error.name !== 'AbortError') status.textContent = error.message;
            else if (id === sequence) status.textContent = 'Pemuatan terlalu lama. Coba cari kembali.';
        } finally {
            window.clearTimeout(timeout);
            if (id === sequence) {
                results().hidden = false;
                results().inert = false;
                skeleton.hidden = true;
                root.removeAttribute('aria-busy');
            }
        }
    };
    const filterUrl = () => {
        const url = new URL(filter.action, window.location.href);
        for (const [key, value] of new FormData(filter)) if (value) url.searchParams.set(key, value);
        return url;
    };
    filter.addEventListener('submit', (event) => { event.preventDefault(); refresh(filterUrl()); });
    filter.addEventListener('input', (event) => {
        if (event.target.name !== 'q' || event.isComposing) return;
        window.clearTimeout(timer);
        controller?.abort();
        sequence++;
        timer = window.setTimeout(() => refresh(filterUrl()), 300);
    });
    filter.addEventListener('change', (event) => { if (event.target.tagName === 'SELECT') refresh(filterUrl()); });
    const syncFilters = (url) => {
        ['q', 'perusahaan', 'status'].forEach((key) => { filter.elements.namedItem(key).value = url.searchParams.get(key) ?? ''; });
    };
    root.addEventListener('click', (event) => {
        const edit = event.target.closest('[data-planning-edit]');
        if (edit && !edit.disabled) {
            triggerUrl = edit.dataset.url;
            form.action = triggerUrl;
            const data = JSON.parse(edit.dataset.plan);
            Object.entries(data).forEach(([key, value]) => { form.elements.namedItem(key).value = value ?? ''; });
            errors.textContent = '';
            modal.showModal();
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
    const close = () => {
        if (saving) return;
        modal.close();
        [...root.querySelectorAll('[data-planning-edit]')].find((button) => button.dataset.url === triggerUrl)?.focus();
    };
    modal.querySelectorAll('[data-planning-close]').forEach((button) => button.addEventListener('click', close));
    modal.addEventListener('cancel', (event) => { event.preventDefault(); close(); });
    const start = form.elements.namedItem('tgl_mulai');
    start.addEventListener('change', () => {
        if (!start.value) return;
        const date = new Date(`${start.value}T00:00:00Z`);
        if (Number.isNaN(date.getTime())) return;
        date.setUTCDate(date.getUTCDate() + 14);
        form.elements.namedItem('tgl_selesai').value = date.toISOString().slice(0, 10);
    });
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (saving) return;
        saving = true;
        errors.textContent = '';
        const body = new FormData(form);
        const controls = [...modal.querySelectorAll('button, input, select')];
        controls.forEach((control) => { control.disabled = true; });
        const save = modal.querySelector('[data-planning-save]');
        save.textContent = 'Menyimpan…';
        try {
            const response = await window.fetch(form.action, { method: 'POST', body, headers: { Accept: 'application/json' } });
            if (!response.headers.get('content-type')?.includes('application/json')) throw new Error('Sesi berakhir atau server tidak merespons. Muat ulang halaman setelah menyalin isian Anda.');
            const data = await response.json();
            if (!response.ok) throw new Error(Object.values(data.errors ?? {}).flat().join(' ') || data.message || 'Rencana gagal disimpan.');
            await refresh(new URL(window.location.href), false);
            saving = false;
            close();
            status.textContent = `${data.message} ${status.textContent}`;
        } catch (error) { errors.textContent = error.message; errors.focus(); }
        finally { saving = false; controls.forEach((control) => { control.disabled = false; }); save.textContent = 'Simpan perubahan'; }
    });
    window.addEventListener('popstate', () => { const url = new URL(window.location.href); syncFilters(url); refresh(url, false); });
}

if (typeof document !== 'undefined') setupPlanningWorkspace(document, window);
