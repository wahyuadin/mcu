export function setupUserManagement(scope) {
    const modal = scope.querySelector('[data-user-form-modal]');
    const form = scope.querySelector('[data-user-form]');
    const deleteModal = scope.querySelector('[data-user-delete-modal]');
    const company = form.elements.namedItem('perusahaan_id');
    const companyField = scope.querySelector('[data-user-company-field]');
    const updateCompany = () => {
        if (!company || !companyField) return;
        const hrd = form.elements.namedItem('role').value === 'hrd';
        companyField.hidden = !hrd;
        company.required = hrd;
        if (!hrd) company.value = '';
    };
    form.elements.namedItem('role').addEventListener('change', updateCompany);
    const passwordToggles = [...form.querySelectorAll('[data-password-toggle]')];
    const avatarInput = form.querySelector('[data-user-avatar-input]');
    const avatarPreview = form.querySelector('[data-user-avatar-preview]');
    const avatarPlaceholder = form.querySelector('[data-user-avatar-placeholder]');
    const avatarMessage = form.querySelector('[data-user-avatar-message]');
    let savedAvatar = form.dataset.avatarUrl ?? '';
    let previewUrl = null;
    const setPasswordVisibility = (button, visible) => {
        form.elements.namedItem(button.dataset.passwordToggle).type = visible ? 'text' : 'password';
        button.setAttribute('aria-pressed', String(visible));
        button.setAttribute('aria-label', `${visible ? 'Sembunyikan' : 'Tampilkan'} ${button.dataset.passwordLabel}`);
        button.querySelector('[data-password-hidden]').hidden = visible;
        button.querySelector('[data-password-visible]').hidden = !visible;
    };
    passwordToggles.forEach((button) => button.addEventListener('click', () => {
        setPasswordVisibility(button, form.elements.namedItem(button.dataset.passwordToggle).type === 'password');
    }));
    const showAvatar = (url) => {
        if (previewUrl) URL.revokeObjectURL(previewUrl);
        previewUrl = null;
        avatarPreview.hidden = !url;
        avatarPlaceholder.hidden = Boolean(url);
        if (url) avatarPreview.src = url;
        else avatarPreview.removeAttribute('src');
    };
    avatarInput.addEventListener('change', () => {
        const file = avatarInput.files[0];
        avatarMessage.hidden = true;
        showAvatar(savedAvatar);
        if (!file) return;
        if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 2 * 1024 * 1024) {
            avatarInput.value = '';
            avatarMessage.textContent = 'Pilih JPG, PNG, atau WebP dengan ukuran maksimal 2 MB.';
            avatarMessage.hidden = false;
            return;
        }
        previewUrl = URL.createObjectURL(file);
        avatarPreview.src = previewUrl;
        avatarPreview.hidden = false;
        avatarPlaceholder.hidden = true;
    });
    avatarPreview.addEventListener('error', () => {
        avatarPreview.hidden = true;
        avatarPlaceholder.hidden = false;
    });
    const showForm = (user = null) => {
        form.reset();
        form.querySelectorAll('.text-red-700, [data-user-field-error]').forEach((error) => { error.hidden = true; });
        form.action = user?.url ?? form.dataset.createUrl;
        form.elements.namedItem('_method').value = user ? 'PUT' : 'POST';
        form.elements.namedItem('_user_id').value = user?.id ?? '';
        for (const field of ['name', 'username', 'email', 'role']) {
            form.elements.namedItem(field).value = user?.[field] ?? (field === 'role' ? 'petugas' : '');
        }
        if (company) company.value = user?.perusahaan_id ?? '';
        updateCompany();
        for (const field of ['password', 'password_confirmation']) {
            const input = form.elements.namedItem(field);
            input.value = '';
            input.required = !user;
        }
        passwordToggles.forEach((button) => setPasswordVisibility(button, false));
        avatarInput.value = '';
        avatarMessage.hidden = true;
        savedAvatar = user?.avatar_url ?? '';
        showAvatar(savedAvatar);
        scope.querySelector('#user-form-title').textContent = user ? 'Ubah user' : 'Tambah user';
        scope.querySelector('[data-user-password-help]').textContent = user ? 'Kosongkan password jika tidak ingin mengubahnya.' : 'Password minimal 8 karakter.';
        modal.showModal();
        form.elements.namedItem('name').focus();
    };

    scope.querySelector('[data-user-create]').addEventListener('click', () => showForm());
    scope.querySelectorAll('[data-user-edit]').forEach((button) => {
        button.addEventListener('click', () => showForm(JSON.parse(button.dataset.userEdit)));
    });
    scope.querySelectorAll('[data-user-delete]').forEach((button) => {
        button.addEventListener('click', () => {
            deleteModal.querySelector('[data-user-delete-name]').textContent = button.dataset.deleteName;
            deleteModal.querySelector('form').action = button.dataset.deleteUrl;
            deleteModal.showModal();
        });
    });
    scope.querySelectorAll('[data-user-modal-close]').forEach((button) => {
        button.addEventListener('click', () => button.closest('dialog').close());
    });
    scope.querySelectorAll('[data-user-modal]').forEach((dialog) => {
        dialog.addEventListener('click', (event) => {
            const rect = dialog.getBoundingClientRect();
            if (event.target === dialog && (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom)) dialog.close();
        });
        dialog.addEventListener('close', () => {
            if (dialog === modal) {
                form.elements.namedItem('password').value = '';
                form.elements.namedItem('password_confirmation').value = '';
                passwordToggles.forEach((button) => setPasswordVisibility(button, false));
                avatarInput.value = '';
                showAvatar(savedAvatar);
            }
        });
    });
    if (modal.dataset.reopen === 'true') modal.showModal();
    updateCompany();
}

if (typeof document !== 'undefined') {
    const scope = document.querySelector('[data-user-management]');
    if (scope) setupUserManagement(scope);
}
