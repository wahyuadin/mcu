export function moveOrderItem(items, from, to) {
    if (from < 0 || to < 0 || from >= items.length || to >= items.length || from === to) return [...items];
    const result = [...items];
    result.splice(to, 0, result.splice(from, 1)[0]);
    return result;
}

export function moveFilteredOrderItem(items, subcategory, from, to) {
    if (!subcategory) return moveOrderItem(items, from, to);
    const matches = (item) => (item.subcategory_id ?? '__none') === subcategory;
    const moved = moveOrderItem(items.filter(matches), from, to);
    let index = 0;
    return items.map((item) => matches(item) ? moved[index++] : item);
}

export function setupItemOrderModal(dialog, root = document) {
    const category = dialog.querySelector('[data-item-order-category]');
    const subcategory = dialog.querySelector('[data-item-order-subcategory]');
    const visibleItems = () => items.filter((item) => !subcategory?.value || (item.subcategory_id ?? '__none') === subcategory.value);
    const noun = category.dataset?.orderNoun ?? (category.hidden ? 'kategori' : 'item');
    const board = dialog.querySelector('[data-item-order-board]');
    const status = dialog.querySelector('[data-item-order-status]');
    const save = dialog.querySelector('[data-item-order-save]');
    const token = dialog.querySelector('[data-item-order-token]').value;
    let items = [];
    let version = null;
    let url = null;
    let draggedId = null;
    let requestId = 0;
    let controller;
    let saving = false;
    let closing = false;
    let dirty = false;
    let loadedCategory = '';
    let trigger;
    let animation;

    const fade = (from, to, exiting = false) => {
        animation?.cancel();
        const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        const easing = getComputedStyle(dialog).getPropertyValue('--ease-out').trim() || 'cubic-bezier(0.23, 1, 0.32, 1)';
        animation = dialog.animate?.([{ opacity: from }, { opacity: to }], { duration: reduced ? 125 : (exiting ? 200 : 220), easing, fill: 'both' });
        return animation?.finished.catch(() => {}) ?? Promise.resolve();
    };

    const render = (focusId = null, direction = null) => {
        board.replaceChildren();
        const visible = visibleItems();
        visible.forEach((item, index) => {
            const card = root.createElement('li');
            card.dataset.orderItem = item.id;
            card.draggable = !saving;
            card.className = 'flex items-center gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm';
            const handle = root.createElement('span');
            handle.textContent = '⠿';
            handle.setAttribute('aria-hidden', 'true');
            handle.className = 'cursor-grab select-none text-xl text-slate-400';
            const position = root.createElement('span');
            position.textContent = String(index + 1);
            position.className = 'flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-sm font-bold text-blue-900';
            const content = root.createElement('div');
            content.className = 'min-w-0 flex-1';
            const label = root.createElement('strong');
            label.textContent = item.label;
            label.className = 'block break-words text-sm';
            const description = root.createElement('p');
            description.textContent = `${item.type} · ${item.is_active ? 'Aktif' : 'Nonaktif'}`;
            description.className = 'mt-1 text-xs text-slate-500';
            content.append(label, description);
            const actions = root.createElement('div');
            actions.className = 'flex shrink-0 gap-2';
            ['up', 'down'].forEach((move) => {
                const button = root.createElement('button');
                button.type = 'button';
                button.dataset.orderMove = move;
                button.textContent = move === 'up' ? '↑' : '↓';
                button.setAttribute('aria-label', `${move === 'up' ? 'Naikkan' : 'Turunkan'} ${item.label}`);
                button.className = 'h-11 w-11 rounded-lg border border-slate-300 bg-white font-bold text-slate-600 disabled:opacity-30';
                button.disabled = saving || (move === 'up' ? index === 0 : index === visible.length - 1);
                actions.append(button);
                if (focusId === item.id && direction === move) queueMicrotask(() => {
                    if (!button.disabled) button.focus();
                    else actions.querySelector('button:not(:disabled)')?.focus();
                });
            });
            card.append(handle, position, content, actions);
            board.append(card);
        });
        save.disabled = saving || !dirty || !version || items.length === 0;
    };

    const load = async () => {
        controller?.abort();
        const current = ++requestId;
        items = [];
        version = null;
        dirty = false;
        loadedCategory = category.value;
        if (subcategory) {
            subcategory.replaceChildren(new Option('Semua subkategori', ''));
            subcategory.disabled = true;
        }
        url = category.selectedOptions[0]?.dataset.url ?? null;
        render();
        if (!url) {
            status.textContent = `Pilih kategori untuk menampilkan kartu ${noun}.`;
            return;
        }
        controller = new AbortController();
        status.textContent = noun === 'kategori' ? 'Memuat seluruh kategori…' : `Memuat seluruh ${noun} kategori…`;
        try {
            const response = await fetch(url, { headers: { Accept: 'application/json' }, cache: 'no-store', signal: controller.signal });
            if (!response.ok || !response.headers.get('content-type')?.includes('application/json')) throw new Error(`Daftar ${noun} tidak dapat dimuat. Periksa koneksi dan sesi login.`);
            const data = await response.json();
            if (current !== requestId || !dialog.open || closing) return;
            items = data.items;
            if (subcategory) {
                (data.subcategories ?? []).forEach((group) => subcategory.append(new Option(group.name, group.id)));
                if (items.some((item) => !item.subcategory_id)) subcategory.append(new Option('Tanpa subkategori', '__none'));
                subcategory.disabled = false;
            }
            version = data.version;
            status.textContent = items.length ? `${data.category} · ${items.length} ${noun}. Urutan dibaca dari atas ke bawah.` : (noun === 'kategori' ? 'Belum ada kategori MCU.' : `Belum ada ${noun} dalam kategori ini.`);
            render();
        } catch (error) {
            if (current === requestId && error.name !== 'AbortError') status.textContent = error.message;
        }
    };

    const close = async () => {
        if (saving || closing || !dialog.open) return;
        closing = true;
        controller?.abort();
        requestId++;
        dialog.dataset.closing = '';
        await fade(Number(getComputedStyle(dialog).opacity), 0, true);
        dialog.close();
        animation?.cancel();
        delete dialog.dataset.closing;
        closing = false;
        trigger?.focus();
    };

    root.querySelectorAll('[data-item-order-open]').forEach((button) => button.addEventListener('click', () => {
        if (dialog.open || closing) return;
        trigger = button;
        category.value = button.dataset.orderCategory ?? root.querySelector(`[name="${button.dataset.categorySelect}"]`)?.value ?? '';
        dialog.showModal();
        fade(0, 1);
        load();
    }));
    dialog.querySelectorAll('[data-item-order-close]').forEach((button) => button.addEventListener('click', close));
    dialog.addEventListener('cancel', (event) => { event.preventDefault(); close(); });
    dialog.addEventListener('click', (event) => {
        if (event.target !== dialog) return;
        const bounds = dialog.getBoundingClientRect();
        if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) close();
    });
    category.addEventListener('change', () => {
        if (dirty && !window.confirm('Urutan belum disimpan. Buang perubahan dan pindah kategori?')) {
            category.value = loadedCategory;
            return;
        }
        load();
    });
    subcategory?.addEventListener('change', () => {
        render();
        status.textContent = `${visibleItems().length} ${noun} ditampilkan. Urutan item di luar filter tetap dipertahankan.`;
    });

    const move = (from, to, focusId = null, direction = null) => {
        const visible = visibleItems();
        if (saving || from === to || from < 0 || to < 0 || to >= visible.length) return;
        const label = visible[from].label;
        items = moveFilteredOrderItem(items, subcategory?.value, from, to);
        dirty = true;
        render(focusId, direction);
        status.textContent = `${label} dipindahkan ke posisi ${to + 1}. Belum disimpan.`;
    };
    board.addEventListener('click', (event) => {
        const button = event.target.closest('[data-order-move]');
        if (!button || button.disabled) return;
        const id = button.closest('[data-order-item]').dataset.orderItem;
        const from = visibleItems().findIndex((item) => item.id === id);
        move(from, from + (button.dataset.orderMove === 'up' ? -1 : 1), id, button.dataset.orderMove);
    });
    board.addEventListener('dragstart', (event) => {
        const card = event.target.closest('[data-order-item]');
        if (!card || saving) { event.preventDefault(); return; }
        draggedId = card.dataset.orderItem;
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', draggedId);
        card.dataset.dragging = '';
    });
    board.addEventListener('dragover', (event) => {
        if (!draggedId || saving) return;
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
    });
    board.addEventListener('drop', (event) => {
        event.preventDefault();
        const target = event.target.closest('[data-order-item]');
        if (!target || !draggedId || target.dataset.orderItem === draggedId || saving) return;
        const from = visibleItems().findIndex((item) => item.id === draggedId);
        let to = visibleItems().findIndex((item) => item.id === target.dataset.orderItem);
        const bounds = target.getBoundingClientRect();
        if (event.clientY > bounds.top + bounds.height / 2) to++;
        if (from < to) to--;
        move(from, to);
        draggedId = null;
    });
    board.addEventListener('dragend', () => {
        draggedId = null;
        board.querySelectorAll('[data-dragging]').forEach((card) => { delete card.dataset.dragging; });
    });

    save.addEventListener('click', async () => {
        if (saving || !dirty || !version || !url) return;
        saving = true;
        category.disabled = true;
        if (subcategory) subcategory.disabled = true;
        dialog.querySelectorAll('[data-item-order-close]').forEach((button) => { button.disabled = true; });
        render();
        status.textContent = 'Menyimpan urutan…';
        try {
            const response = await fetch(url, { method: 'PUT', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token }, body: JSON.stringify({ version, items: items.map((item) => item.id) }) });
            if (!response.headers.get('content-type')?.includes('application/json')) throw new Error('Sesi berakhir atau server tidak merespons. Muat ulang setelah menyimpan perubahan form Anda.');
            const data = await response.json();
            if (!response.ok) throw new Error(Object.values(data.errors ?? {}).flat().join(' ') || data.message || 'Urutan gagal disimpan.');
            version = data.version;
            dirty = false;
            status.textContent = data.message;
        } catch (error) {
            status.textContent = error.message;
        } finally {
            saving = false;
            category.disabled = false;
            if (subcategory) subcategory.disabled = false;
            dialog.querySelectorAll('[data-item-order-close]').forEach((button) => { button.disabled = false; });
            render();
        }
    });
}

if (typeof document !== 'undefined') {
    document.querySelectorAll('[data-item-order-modal]').forEach((dialog) => setupItemOrderModal(dialog));
}
