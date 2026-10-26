import assert from 'node:assert/strict';
import { test } from 'node:test';
import { setupUserManagement } from '../../resources/js/user-management.js';

class Element {
    constructor(dataset = {}) { this.dataset = dataset; this.events = {}; this.value = ''; }
    addEventListener(name, callback) { this.events[name] = callback; }
    showModal() { this.open = true; }
    close() { this.open = false; this.events.close?.(); }
    focus() { this.focused = true; }
    setAttribute(name, value) { this[name] = value; }
    removeAttribute(name) { delete this[name]; }
    getBoundingClientRect() { return { left: 10, right: 100, top: 10, bottom: 100 }; }
}

function harness({ reopen = false } = {}) {
    const fields = Object.fromEntries(['_method', '_user_id', 'name', 'username', 'email', 'role', 'perusahaan_id', 'password', 'password_confirmation'].map((name) => [name, new Element()]));
    const companyField = new Element();
    const error = new Element();
    const form = new Element({ createUrl: '/users' });
    form.elements = { namedItem: (name) => fields[name] };
    form.reset = () => Object.values(fields).forEach((field) => { field.value = ''; });
    const toggles = ['password', 'password_confirmation'].map((field) => {
        fields[field].type = 'password';
        const button = new Element({ passwordToggle: field, passwordLabel: field === 'password' ? 'password' : 'konfirmasi password' });
        const icons = { '[data-password-hidden]': new Element(), '[data-password-visible]': new Element() };
        button.querySelector = (selector) => icons[selector];
        return button;
    });
    const avatarInput = new Element();
    avatarInput.files = [];
    const avatarPreview = new Element();
    const avatarPlaceholder = new Element();
    const avatarMessage = new Element();
    form.querySelector = (selector) => ({ '[data-user-avatar-input]': avatarInput, '[data-user-avatar-preview]': avatarPreview, '[data-user-avatar-placeholder]': avatarPlaceholder, '[data-user-avatar-message]': avatarMessage })[selector];
    form.querySelectorAll = (selector) => selector === '[data-password-toggle]' ? toggles : [error];
    const modal = new Element({ reopen: String(reopen) });
    const deleteModal = new Element();
    const bulkModal = new Element();
    const deleteForm = new Element();
    const deleteName = new Element();
    deleteModal.querySelector = (selector) => selector === 'form' ? deleteForm : deleteName;
    const title = new Element();
    const help = new Element();
    const create = new Element();
    const user = { id: 'user-id', name: 'Nama "Panjang"', email: 'test@example.test', username: null, role: 'petugas', url: '/users/user-id' };
    const edit = new Element({ userEdit: JSON.stringify(user) });
    const remove = new Element({ deleteName: '<script>not HTML</script>', deleteUrl: '/users/user-id' });
    const closes = [modal, deleteModal, bulkModal].map((dialog) => {
        const button = new Element();
        button.closest = () => dialog;
        return button;
    });
    const scope = {
        querySelector: (selector) => ({ '[data-user-form-modal]': modal, '[data-user-form]': form, '[data-user-company-field]': companyField, '[data-user-delete-modal]': deleteModal, '#user-form-title': title, '[data-user-password-help]': help, '[data-user-create]': create })[selector],
        querySelectorAll: (selector) => ({ '[data-user-edit]': [edit], '[data-user-delete]': [remove], '[data-user-modal-close]': closes, '[data-user-modal]': [modal, deleteModal, bulkModal] })[selector],
    };
    setupUserManagement(scope);
    return { fields, companyField, error, form, modal, deleteModal, deleteForm, deleteName, title, help, create, edit, remove, closes, user, toggles, avatarInput, avatarPreview, avatarPlaceholder, avatarMessage };
}

test('create opens empty modal with POST action, required passwords, and focused name', () => {
    const h = harness();
    h.create.events.click();
    assert.equal(h.modal.open, true);
    assert.equal(h.form.action, '/users');
    assert.equal(h.fields._method.value, 'POST');
    assert.equal(h.fields._user_id.value, '');
    assert.equal(h.fields.role.value, 'petugas');
    assert.equal(h.fields.password.required, true);
    assert.equal(h.fields.password_confirmation.required, true);
    assert.equal(h.fields.name.focused, true);
    assert.equal(h.error.hidden, true);
});

test('edit fills user fields, allows blank password, and create does not reuse edited values', () => {
    const h = harness();
    h.edit.events.click();
    assert.equal(h.form.action, h.user.url);
    assert.equal(h.fields._method.value, 'PUT');
    assert.equal(h.fields._user_id.value, h.user.id);
    assert.equal(h.fields.name.value, h.user.name);
    assert.equal(h.fields.username.value, '');
    assert.equal(h.fields.email.value, h.user.email);
    assert.equal(h.fields.role.value, 'petugas');
    assert.equal(h.fields.password.required, false);
    assert.equal(h.fields.password.value, '');
    h.fields.password.value = 'secret';
    h.fields.password_confirmation.value = 'secret';
    h.closes[0].events.click();
    assert.equal(h.modal.open, false);
    assert.equal(h.fields.password.value, '');
    assert.equal(h.fields.password_confirmation.value, '');
    h.create.events.click();
    assert.equal(h.fields.email.value, '');
    assert.equal(h.fields.password.required, true);
    assert.equal(h.title.textContent, 'Tambah user');
});

test('single delete opens a confirmation with safe text and cancel closes it', () => {
    const h = harness();
    h.remove.events.click();
    assert.equal(h.deleteModal.open, true);
    assert.equal(h.deleteName.textContent, h.remove.dataset.deleteName);
    assert.equal(h.deleteForm.action, '/users/user-id');
    h.closes[1].events.click();
    assert.equal(h.deleteModal.open, false);
});

test('clicking outside closes modal but clicking its padding does not', () => {
    const h = harness();
    h.create.events.click();
    h.modal.events.click({ target: h.modal, clientX: 50, clientY: 50 });
    assert.equal(h.modal.open, true);
    h.modal.events.click({ target: h.modal, clientX: 0, clientY: 0 });
    assert.equal(h.modal.open, false);
});

test('validation failure reopens form modal automatically', () => {
    const h = harness({ reopen: true });
    assert.equal(h.modal.open, true);
    assert.equal(h.error.hidden, undefined);
});

test('password toggles are independent and closing resets both to hidden', () => {
    const h = harness();
    h.create.events.click();
    h.fields.password.value = 'secret';
    h.toggles[0].events.click();
    assert.equal(h.fields.password.type, 'text');
    assert.equal(h.fields.password.value, 'secret');
    assert.equal(h.fields.password_confirmation.type, 'password');
    assert.equal(h.toggles[0]['aria-pressed'], 'true');
    assert.equal(h.toggles[0]['aria-label'], 'Sembunyikan password');
    h.toggles[1].events.click();
    assert.equal(h.fields.password_confirmation.type, 'text');
    h.closes[0].events.click();
    assert.equal(h.fields.password.type, 'password');
    assert.equal(h.fields.password_confirmation.type, 'password');
    assert.equal(h.toggles[0]['aria-pressed'], 'false');
});

test('avatar previews uploads, restores saved photo on cancel, and defaults to user icon on create', () => {
    const h = harness();
    h.edit.dataset.userEdit = JSON.stringify({ ...h.user, avatar_url: '/users/user-id/avatar' });
    h.edit.events.click();
    assert.equal(h.avatarPreview.src, '/users/user-id/avatar');
    assert.equal(h.avatarPlaceholder.hidden, true);
    h.avatarInput.files = [new Blob(['image'], { type: 'image/png' })];
    h.avatarInput.events.change();
    assert.ok(h.avatarPreview.src.startsWith('blob:'));
    assert.equal(h.avatarPreview.hidden, false);
    h.closes[0].events.click();
    assert.equal(h.avatarInput.value, '');
    assert.equal(h.avatarPreview.src, '/users/user-id/avatar');
    h.create.events.click();
    assert.equal(h.avatarPreview.src, undefined);
    assert.equal(h.avatarPreview.hidden, true);
    assert.equal(h.avatarPlaceholder.hidden, false);
});

test('invalid or oversized uploads are cleared without leaving a stale preview', () => {
    const h = harness();
    h.create.events.click();
    h.avatarInput.files = [new Blob(['image'], { type: 'image/png' })];
    h.avatarInput.events.change();
    h.avatarInput.files = [{ type: 'image/png', size: 2 * 1024 * 1024 + 1 }];
    h.avatarInput.events.change();
    assert.equal(h.avatarInput.value, '');
    assert.equal(h.avatarMessage.hidden, false);
    assert.equal(h.avatarPreview.hidden, true);
    h.avatarInput.files = [{ type: 'image/svg+xml', size: 100 }];
    h.avatarInput.events.change();
    assert.equal(h.avatarMessage.hidden, false);
    assert.equal(h.avatarPlaceholder.hidden, false);
});

test('HRD requires a company while internal roles hide and clear the association', () => {
    const h = harness(); h.create.events.click(); h.fields.role.value = 'hrd'; h.fields.role.events.change();
    assert.equal(h.companyField.hidden, false); assert.equal(h.fields.perusahaan_id.required, true);
    h.fields.perusahaan_id.value = 'company-a'; h.fields.role.value = 'petugas'; h.fields.role.events.change();
    assert.equal(h.companyField.hidden, true); assert.equal(h.fields.perusahaan_id.value, ''); assert.equal(h.fields.perusahaan_id.required, false);
});

test('editing an HRD account preserves its company and creating a new account does not inherit it', () => {
    const h = harness(); h.edit.dataset.userEdit = JSON.stringify({ ...h.user, role: 'hrd', perusahaan_id: 'company-a' }); h.edit.events.click();
    assert.equal(h.fields.perusahaan_id.value, 'company-a'); assert.equal(h.companyField.hidden, false);
    h.closes[0].events.click(); h.create.events.click(); assert.equal(h.fields.perusahaan_id.value, ''); assert.equal(h.companyField.hidden, true);
});
