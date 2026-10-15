import assert from 'node:assert/strict';
import { test } from 'node:test';
import { setupAccountMenu } from '../../resources/js/account-menu.js';

class Element {
    constructor(dataset = {}) { this.dataset = dataset; this.events = {}; this.style = {}; this.value = ''; this.defaultValue = ''; this.disabled = false; }
    addEventListener(name, callback) { this.events[name] = callback; }
    setAttribute(name, value) { this[name] = value; }
    removeAttribute(name) { delete this[name]; }
    focus() { this.focused = true; }
    getBoundingClientRect() { return { left: 16, right: 380, top: 16, bottom: 80 }; }
    showModal() { this.open = true; }
    close() { this.open = false; this.events.close?.(); }
}

async function harness(run) {
    const originals = { window: globalThis.window, FormData: globalThis.FormData, fetch: globalThis.fetch };
    const createForm = (kind, names) => {
        const form = new Element(kind === 'profile' ? { avatarUrl: '' } : {});
        const fields = Object.fromEntries([...names, '_token', '_method'].map((name) => [name, new Element()]));
        fields._token.value = 'csrf-original';
        fields._method.value = kind === 'profile' ? 'PATCH' : 'PUT';
        names.forEach((name) => { fields[name].type = kind === 'password' ? 'password' : 'text'; });
        const elements = Object.values(fields);
        elements.namedItem = (name) => fields[name];
        form.elements = elements;
        form.action = kind === 'profile' ? '/profil-saya' : '/profil-saya/password';
        form.reset = () => elements.forEach((input) => { input.value = input.defaultValue; });
        const errors = names.map((field) => new Element({ accountError: field }));
        const status = new Element();
        const save = new Element(); save.textContent = kind === 'profile' ? 'Simpan profil' : 'Simpan password';
        const close = new Element();
        const dialog = new Element();
        dialog.querySelector = () => form;
        dialog.querySelectorAll = () => [close];
        form.closest = () => dialog;
        form.querySelectorAll = (selector) => selector === '[data-account-error]' ? errors : [...elements, save, close];
        form.querySelector = (selector) => selector === '[data-account-save]' ? save : selector === '[data-account-status]' ? status : errors.find((error) => selector.includes(`"${error.dataset.accountError}"`));
        return { form, fields, errors, status, save, dialog, close };
    };
    const profile = createForm('profile', ['name', 'email', 'avatar']);
    const password = createForm('password', ['current_password', 'password', 'password_confirmation']);
    profile.fields.avatar.files = [];
    const trigger = new Element();
    const menu = new Element(); menu.hidePopover = () => { menu.open = false; }; menu.matches = () => menu.open;
    const openProfile = new Element();
    const openPassword = new Element();
    const preview = new Element();
    const previewIcon = new Element();
    const avatar = new Element();
    const avatarIcon = new Element();
    const name = new Element();
    const logoutToken = new Element();
    const toggles = ['current_password', 'password', 'password_confirmation'].map((field) => {
        const button = new Element({ accountPasswordToggle: field, passwordLabel: field });
        const slash = new Element();
        button.querySelector = () => slash;
        return button;
    });
    const root = {
        querySelector: (selector) => ({ '[data-account-trigger]': trigger, '[data-account-menu]': menu, '[data-account-form="profile"]': profile.form, '[data-account-form="password"]': password.form, '[data-profile-avatar-input]': profile.fields.avatar, '[data-profile-avatar]': preview, '[data-profile-avatar-icon]': previewIcon, '[data-account-avatar]': avatar, '[data-account-avatar-icon]': avatarIcon, '[data-account-profile-open]': openProfile, '[data-account-password-open]': openPassword, '[data-account-name]': name })[selector],
        querySelectorAll: (selector) => selector === '[data-account-password-toggle]' ? toggles : [profile.dialog, password.dialog],
        ownerDocument: { querySelectorAll: () => [profile.fields._token, password.fields._token, logoutToken] },
    };
    globalThis.window = { innerWidth: 390, addEventListener() {} };
    globalThis.FormData = class {
        constructor(form) {
            this.values = new Map(Object.entries(form === profile.form ? profile.fields : password.fields).filter(([, input]) => !input.disabled).map(([field, input]) => [field, field === 'avatar' ? input.files[0] : input.value]));
        }
        get(name) { return this.values.get(name); }
    };
    const requests = [];
    const respond = (status, data) => { globalThis.fetch = async (url, options) => { requests.push({ url, ...options }); return { status, ok: status === 200, headers: { get: () => 'application/json' }, json: async () => data }; }; };
    setupAccountMenu(root);
    try { await run({ profile, password, trigger, menu, openProfile, openPassword, preview, previewIcon, avatar, avatarIcon, name, toggles, logoutToken, respond, requests }); }
    finally { Object.entries(originals).forEach(([key, value]) => { if (value === undefined) delete globalThis[key]; else globalThis[key] = value; }); }
}

test('menu fits mobile viewport and profile/password open without a network request', async () => {
    await harness(async (h) => {
        h.menu.events.beforetoggle({ newState: 'open' });
        assert.equal(h.menu.style.width, '320px');
        assert.equal(h.menu.style.left, '54px');
        assert.equal(h.trigger['aria-expanded'], 'true');
        h.openProfile.events.click();
        assert.equal(h.profile.dialog.open, true);
        assert.equal(h.profile.fields.name.focused, true);
        h.openPassword.events.click();
        assert.equal(h.password.dialog.open, true);
        assert.equal(h.password.fields.current_password.focused, true);
        assert.equal(h.requests.length, 0);
    });
});

test('profile validation stays in modal and retains draft inputs and selected file', async () => {
    await harness(async (h) => {
        h.openProfile.events.click();
        h.profile.fields.name.value = 'Draft';
        h.profile.fields.email.value = 'duplicate@example.test';
        const file = { type: 'image/png', size: 100 };
        h.profile.fields.avatar.files = [file];
        h.respond(422, { errors: { email: ['Email sudah dipakai.'] } });
        await h.profile.form.events.submit({ preventDefault() {} });
        assert.equal(h.profile.dialog.open, true);
        assert.equal(h.profile.fields.name.value, 'Draft');
        assert.equal(h.profile.fields.avatar.files[0], file);
        assert.equal(h.requests[0].body.get('avatar'), file);
        assert.equal(h.requests[0].headers['X-CSRF-TOKEN'], 'csrf-original');
        assert.equal(h.profile.errors.find((error) => error.dataset.accountError === 'email').textContent, 'Email sudah dipakai.');
        assert.equal(h.profile.fields.email.focused, true);
        assert.equal(h.profile.save.disabled, false);
    });
});

test('profile success updates navbar name and master avatar safely without navigation', async () => {
    await harness(async (h) => {
        h.openProfile.events.click();
        h.respond(200, { message: 'Profil disimpan.', user: { name: '<script>not HTML</script>', email: 'updated@example.test', avatar_url: '/users/me/avatar?v=123' } });
        await h.profile.form.events.submit({ preventDefault() {} });
        assert.equal(h.name.textContent, '<script>not HTML</script>');
        assert.equal(h.avatar.src, '/users/me/avatar?v=123');
        assert.equal(h.avatarIcon.hidden, true);
        assert.equal(h.profile.fields.email.defaultValue, 'updated@example.test');
        assert.equal(h.profile.status.textContent, 'Profil disimpan.');
        assert.equal(h.profile.dialog.open, false);
        assert.equal(h.trigger.focused, true);
    });
});

test('wrong previous password remains retryable with all fields intact', async () => {
    await harness(async (h) => {
        h.openPassword.events.click();
        h.password.fields.current_password.value = 'wrong';
        h.password.fields.password.value = 'new-secret';
        h.password.fields.password_confirmation.value = 'new-secret';
        h.respond(422, { errors: { current_password: ['Password sebelumnya tidak sesuai.'] } });
        await h.password.form.events.submit({ preventDefault() {} });
        assert.equal(h.password.dialog.open, true);
        assert.equal(h.password.fields.password.value, 'new-secret');
        assert.equal(h.password.fields.current_password.value, 'wrong');
        assert.equal(h.password.fields.current_password['aria-invalid'], 'true');
        assert.equal(h.password.save.textContent, 'Simpan password');
        h.password.close.events.click();
        assert.equal(h.password.fields.password.value, '');
    });
});

test('successful password change clears secrets and refreshes CSRF for every page form', async () => {
    await harness(async (h) => {
        h.openPassword.events.click();
        h.password.fields.password.value = 'new-secret';
        h.toggles[1].events.click();
        assert.equal(h.password.fields.password.type, 'text');
        h.respond(200, { message: 'Password disimpan.', csrf_token: 'csrf-new' });
        await h.password.form.events.submit({ preventDefault() {} });
        assert.equal(h.password.fields.password.value, '');
        assert.equal(h.password.fields.password.type, 'password');
        assert.equal(h.profile.fields._token.value, 'csrf-new');
        assert.equal(h.logoutToken.value, 'csrf-new');
        assert.equal(h.password.dialog.open, false);
    });
});

test('network errors preserve draft and restore save button for retry', async () => {
    await harness(async (h) => {
        h.profile.fields.name.value = 'Draft';
        globalThis.fetch = async () => { throw new TypeError('network'); };
        await h.profile.form.events.submit({ preventDefault() {} });
        assert.equal(h.profile.fields.name.value, 'Draft');
        assert.equal(h.profile.save.disabled, false);
        assert.match(h.profile.status.textContent, /Koneksi gagal/);
    });
});

test('submitting blocks duplicate requests and Escape until response completes', async () => {
    await harness(async (h) => {
        let resolve;
        let calls = 0;
        globalThis.fetch = () => { calls++; return new Promise((done) => { resolve = done; }); };
        h.openProfile.events.click();
        const pending = h.profile.form.events.submit({ preventDefault() {} });
        await h.profile.form.events.submit({ preventDefault() {} });
        let prevented = false;
        h.profile.dialog.events.cancel({ preventDefault() { prevented = true; } });
        h.profile.close.events.click();
        assert.equal(h.profile.dialog.open, true);
        assert.equal(prevented, true);
        assert.equal(calls, 1);
        resolve({ status: 500, ok: false, headers: { get: () => 'text/html' } });
        await pending;
        assert.equal(h.profile.save.disabled, false);
    });
});

test('expired session and rate limits display inline messages without reloading', async () => {
    await harness(async (h) => {
        h.respond(419, {});
        await h.profile.form.events.submit({ preventDefault() {} });
        assert.match(h.profile.status.textContent, /Sesi telah berakhir/);
        h.respond(429, {});
        await h.password.form.events.submit({ preventDefault() {} });
        assert.match(h.password.status.textContent, /Terlalu banyak percobaan/);
    });
});
