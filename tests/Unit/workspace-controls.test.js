import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import { test } from 'node:test';

const source = (file) => readFileSync(new URL(`../../resources/js/${file}.js`, import.meta.url), 'utf8');
class Element {
    constructor() { this.events = {}; this.dataset = {}; this.disabled = false; this.value = ''; }
    addEventListener(type, fn) { this.events[type] = fn; }
    focus() { this.focused = true; }
}

test('Select2 mounts once, respects disabled fieldsets, forwards changes, and cleans up on SPA unmount', () => {
    const events = {}, select = new Element(), state = {}, counters = { mounts: 0, destroys: 0, changes: 0 };
    select.matches = () => true;
    select.closest = () => null;
    select.dispatchEvent = () => { counters.changes++; state.change({ originalEvent: {} }); };
    const wrapper = {
        data: () => state.mounted,
        select2: (options) => {
            if (options === 'destroy') { counters.destroys++; state.mounted = false; }
            else { counters.mounts++; state.mounted = true; }
            return wrapper;
        },
        on: (_, fn) => { state.change = fn; return wrapper; },
        off: () => wrapper,
    };
    const document = { body: {}, querySelectorAll: () => [select], addEventListener: (type, fn) => { events[type] = fn; } };
    runInNewContext(source('modern-select').replace(/^import .*;\r?\n/gm, '').replace('export function', 'function'), { document, window: {}, $: () => wrapper, installSelect2() {}, Event });
    assert.equal(counters.mounts, 1);
    assert.equal(select.disabled, true);
    events['mcu:exam-mounted']();
    assert.equal(counters.mounts, 1);
    state.change({});
    assert.equal(counters.changes, 1);
    events['mcu:exam-unmount']();
    assert.equal(counters.destroys, 1);
    events['mcu:exam-mounted']();
    assert.equal(counters.mounts, 2);
});

test('delete modal names the patient safely, focuses Cancel, and blocks duplicate submits and closing while busy', () => {
    const dialog = new Element(), form = new Element(), cancel = new Element(), confirm = new Element(), name = new Element(), trigger = new Element(), events = {};
    trigger.dataset = { url: '/pasien/identitas/one', name: '<script>not markup</script>' };
    dialog.querySelector = (selector) => ({ form, '[data-patient-delete-cancel]': cancel, '[data-patient-delete-confirm]': confirm, '[data-patient-delete-name]': name })[selector];
    dialog.showModal = () => { dialog.open = true; };
    dialog.close = () => { dialog.open = false; };
    runInNewContext(source('patient-actions'), {
        document: { querySelector: () => dialog, querySelectorAll: () => [trigger] },
        window: { addEventListener: (type, fn) => { events[type] = fn; } },
    });
    trigger.events.click();
    assert.equal(dialog.open, true);
    assert.equal(name.textContent, '<script>not markup</script>');
    assert.equal(form.action, '/pasien/identitas/one');
    assert.equal(cancel.focused, true);
    cancel.events.click();
    assert.equal(dialog.open, false);
    assert.equal(trigger.focused, true);
    trigger.events.click();
    form.events.submit({ preventDefault() { assert.fail('first submit must proceed'); } });
    assert.equal(confirm.disabled, true);
    assert.equal(confirm.textContent, 'Menghapus…');
    let prevented = false;
    form.events.submit({ preventDefault() { prevented = true; } });
    assert.equal(prevented, true);
    cancel.events.click();
    assert.equal(dialog.open, true);
    events.pageshow();
    assert.equal(confirm.disabled, false);
});

test('realtime delete uses delegated buttons after table replacement and returns backend errors inside the modal', async () => {
    const dialog = new Element(), form = new Element(), cancel = new Element(), confirm = new Element(), name = new Element(), error = new Element(), listeners = {}, notifications = [];
    dialog.dataset.realtime = 'true';
    dialog.querySelector = (selector) => ({ form, '[data-patient-delete-cancel]': cancel, '[data-patient-delete-confirm]': confirm, '[data-patient-delete-name]': name, '[data-patient-delete-error]': error })[selector];
    dialog.showModal = () => { dialog.open = true; };
    dialog.close = () => { dialog.open = false; };
    let request, resolveRequest;
    runInNewContext(source('patient-actions'), {
        document: { querySelector: () => dialog, addEventListener: (type, fn) => { listeners[type] = fn; }, dispatchEvent: (event) => notifications.push(event) },
        window: { addEventListener() {}, setTimeout: () => 1, clearTimeout() {} },
        FormData: class { constructor() { this.controlsDisabled = confirm.disabled; } }, AbortController,
        CustomEvent: class { constructor(type, options) { this.type = type; this.detail = options.detail; } },
        fetch: (url, options) => { request = { url, options }; return new Promise((resolve) => { resolveRequest = resolve; }); },
    });
    const trigger = new Element();
    trigger.dataset = { url: '/pasien/identitas/new-row', name: 'Pasien setelah filter', patientId: 'new-row' };
    listeners.click({ target: { closest: () => trigger } });
    assert.equal(name.textContent, 'Pasien setelah filter');
    const save = form.events.submit({ preventDefault() {} });
    assert.equal(request.options.body.controlsDisabled, false);
    resolveRequest({ ok: false, headers: { get: () => 'application/json' }, json: async () => ({ errors: { pasien: ['Pasien sudah memiliki kunjungan.'] } }) });
    await save;
    assert.equal(error.textContent, 'Pasien sudah memiliki kunjungan.');
    assert.equal(dialog.open, true);
    assert.equal(confirm.disabled, false);
    const retry = form.events.submit({ preventDefault() {} });
    resolveRequest({ ok: true, headers: { get: () => 'application/json' }, json: async () => ({ message: 'Pasien dihapus.' }) });
    await retry;
    assert.equal(dialog.open, false);
    assert.equal(notifications[0].type, 'mcu:patient-updated');
    assert.equal(notifications[0].detail.id, 'new-row');
});

test('applying a package template preserves visit context and replaces only editable configuration', async () => {
    const form = new Element(), select = new Element(), button = new Element(), status = new Element();
    const name = { value: '' }, description = { value: '' };
    const inputs = [{ name: 'master_items[]', value: 'active', checked: false }, { name: 'master_items[]', value: 'other', checked: true }, { name: 'items[]', value: 'bmi', checked: false }];
    let confirmations = 0, changes = 0;
    select.selectedOptions = [{ dataset: { url: '/paket/source/template' } }];
    form.querySelector = (selector) => ({ '[data-package-template-select]': select, '[data-package-template-apply]': button, '[data-package-template-status]': status })[selector] ?? null;
    form.querySelectorAll = () => inputs;
    form.elements = { namedItem: (key) => key === 'nama_paket' ? name : description };
    form.dispatchEvent = () => { changes++; };
    let requests = 0;
    runInNewContext(source('package-template'), {
        document: { querySelector: () => form }, Event,
        FormData: class { *[Symbol.iterator]() { yield ['nama_paket', name.value]; yield ['rencana_kunjungan_id', 'current-visit']; } },
        window: { confirm: () => { confirmations++; return true; } },
        fetch: async () => { requests++; return { ok: true, headers: { get: () => 'application/json' }, json: async () => ({ nama_paket: 'Salinan Paket', deskripsi: 'Deskripsi', master_items: ['active'], items: ['bmi'], unavailable: ['Nonaktif'] }) }; },
    });
    name.value = 'Draft user';
    await button.events.click();
    assert.equal(confirmations, 1);
    assert.equal(requests, 1);
    assert.equal(name.value, 'Salinan Paket');
    assert.equal(description.value, 'Deskripsi');
    assert.deepEqual(inputs.map((input) => input.checked), [true, false, true]);
    assert.equal(changes, 1);
    assert.match(status.textContent, /Nonaktif/);
    assert.equal(button.disabled, false);
});
