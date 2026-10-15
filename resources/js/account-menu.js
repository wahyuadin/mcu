export function setupAccountMenu(root) {
    const trigger = root.querySelector('[data-account-trigger]');
    const menu = root.querySelector('[data-account-menu]');
    const profile = root.querySelector('[data-account-form="profile"]');
    const password = root.querySelector('[data-account-form="password"]');
    const avatarInput = root.querySelector('[data-profile-avatar-input]');
    const preview = root.querySelector('[data-profile-avatar]');
    const previewIcon = root.querySelector('[data-profile-avatar-icon]');
    const navbarAvatar = root.querySelector('[data-account-avatar]');
    const navbarIcon = root.querySelector('[data-account-avatar-icon]');
    let savedAvatar = profile.dataset.avatarUrl;
    let previewUrl;
    const busy = new Set();
    const showImage = (image, icon, url) => {
        image.hidden = !url;
        icon.hidden = Boolean(url);
        if (url) image.src = url;
        else image.removeAttribute('src');
    };
    const restorePreview = () => {
        if (previewUrl) URL.revokeObjectURL(previewUrl);
        previewUrl = null;
        showImage(preview, previewIcon, savedAvatar);
    };
    [preview, navbarAvatar].forEach((image) => image.addEventListener('error', () => {
        showImage(image, image === preview ? previewIcon : navbarIcon, '');
    }));
    menu.addEventListener('beforetoggle', (event) => {
        trigger.setAttribute('aria-expanded', String(event.newState === 'open'));
        if (event.newState !== 'open') return;
        const rect = trigger.getBoundingClientRect();
        const width = Math.min(320, window.innerWidth - 32);
        menu.style.width = `${width}px`;
        menu.style.left = `${Math.max(16, Math.min(rect.right - width, window.innerWidth - width - 16))}px`;
        menu.style.top = `${rect.bottom + 8}px`;
    });
    window.addEventListener('resize', () => { if (menu.matches(':popover-open')) menu.hidePopover(); });
    root.querySelector('[data-account-profile-open]').addEventListener('click', () => {
        menu.hidePopover();
        profile.closest('dialog').showModal();
        profile.elements.namedItem('name').focus();
    });
    const toggles = [...root.querySelectorAll('[data-account-password-toggle]')];
    const setVisibility = (button, visible) => {
        password.elements.namedItem(button.dataset.accountPasswordToggle).type = visible ? 'text' : 'password';
        button.setAttribute('aria-pressed', String(visible));
        button.setAttribute('aria-label', `${visible ? 'Sembunyikan' : 'Tampilkan'} ${button.dataset.passwordLabel}`);
        button.querySelector('[data-password-slash]').hidden = !visible;
    };
    toggles.forEach((button) => button.addEventListener('click', () => setVisibility(button, password.elements.namedItem(button.dataset.accountPasswordToggle).type === 'password')));
    const clearErrors = (form) => {
        form.querySelectorAll('[data-account-error]').forEach((error) => { error.hidden = true; error.textContent = ''; });
        [...form.elements].forEach((input) => input.removeAttribute('aria-invalid'));
    };
    root.querySelector('[data-account-password-open]').addEventListener('click', () => {
        password.reset();
        toggles.forEach((button) => setVisibility(button, false));
        clearErrors(password);
        password.querySelector('[data-account-status]').textContent = '';
        password.closest('dialog').showModal();
        password.elements.namedItem('current_password').focus();
    });
    root.querySelectorAll('[data-account-dialog]').forEach((dialog) => {
        const form = dialog.querySelector('form');
        const close = () => { if (!busy.has(form)) dialog.close(); };
        dialog.querySelectorAll('[data-account-close]').forEach((button) => button.addEventListener('click', close));
        dialog.addEventListener('cancel', (event) => { if (busy.has(form)) event.preventDefault(); });
        dialog.addEventListener('click', (event) => {
            if (event.target !== dialog) return;
            const rect = dialog.getBoundingClientRect();
            if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) close();
        });
        dialog.addEventListener('close', () => {
            if (form === password) {
                password.reset();
                toggles.forEach((button) => setVisibility(button, false));
            } else {
                avatarInput.value = '';
                restorePreview();
                trigger.focus();
            }
        });
    });
    avatarInput.addEventListener('change', () => {
        const error = profile.querySelector('[data-account-error="avatar"]');
        error.hidden = true;
        restorePreview();
        const file = avatarInput.files[0];
        if (!file) return;
        if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 2 * 1024 * 1024) {
            avatarInput.value = '';
            error.textContent = 'Pilih JPG, PNG, atau WebP dengan ukuran maksimal 2 MB.';
            error.hidden = false;
            return;
        }
        previewUrl = URL.createObjectURL(file);
        showImage(preview, previewIcon, previewUrl);
    });
    [profile, password].forEach((form) => form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (busy.has(form)) return;
        clearErrors(form);
        const body = new FormData(form);
        const controls = [...form.querySelectorAll('input, button')].map((input) => [input, input.disabled]);
        const save = form.querySelector('[data-account-save]');
        const label = save.textContent;
        const status = form.querySelector('[data-account-status]');
        let invalidInput;
        let succeeded = false;
        status.textContent = '';
        busy.add(form);
        form.setAttribute('aria-busy', 'true');
        controls.forEach(([input]) => { input.disabled = true; });
        save.textContent = 'Menyimpan…';
        try {
            const response = await fetch(form.action, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': body.get('_token') } });
            if ([401, 419].includes(response.status)) throw new Error('Sesi telah berakhir. Muat ulang halaman lalu login kembali.');
            if (response.status === 429) throw new Error('Terlalu banyak percobaan. Tunggu sebentar lalu coba lagi.');
            if (!response.headers.get('content-type')?.includes('application/json')) throw new Error('Server tidak dapat memproses permintaan. Silakan coba lagi.');
            const data = await response.json();
            if (!response.ok) {
                form.querySelectorAll('[data-account-error]').forEach((error) => {
                    const messages = data.errors?.[error.dataset.accountError];
                    if (!messages) return;
                    error.textContent = messages.join(' ');
                    error.hidden = false;
                    const input = form.elements.namedItem(error.dataset.accountError);
                    input?.setAttribute('aria-invalid', 'true');
                    invalidInput ??= input;
                });
                throw new Error(response.status === 422 ? 'Periksa isian yang ditandai, lalu coba kembali.' : 'Penyimpanan gagal. Silakan coba lagi.');
            }
            if (form === profile) {
                root.querySelector('[data-account-name]').textContent = data.user.name;
                for (const field of ['name', 'email']) {
                    const input = profile.elements.namedItem(field);
                    input.value = input.defaultValue = data.user[field];
                }
                savedAvatar = data.user.avatar_url ?? '';
                profile.dataset.avatarUrl = savedAvatar;
                avatarInput.value = '';
                restorePreview();
                showImage(navbarAvatar, navbarIcon, savedAvatar);
            } else {
                password.reset();
                toggles.forEach((button) => setVisibility(button, false));
                if (data.csrf_token) root.ownerDocument.querySelectorAll('input[name="_token"]').forEach((input) => { input.value = input.defaultValue = data.csrf_token; });
            }
            status.textContent = data.message;
            succeeded = true;
        } catch (error) {
            status.textContent = error instanceof TypeError ? 'Koneksi gagal. Isian Anda tetap tersimpan; silakan coba lagi.' : error.message;
        } finally {
            controls.forEach(([input, disabled]) => { input.disabled = disabled; });
            save.textContent = label;
            form.removeAttribute('aria-busy');
            busy.delete(form);
            invalidInput?.focus();
            if (succeeded) form.closest('dialog').close();
        }
    }));
}

if (typeof document !== 'undefined') {
    const root = document.querySelector('[data-account]');
    if (root) setupAccountMenu(root);
}
