import assert from 'node:assert/strict';
import { test } from 'node:test';
import { setupPatientWorkspace } from '../../resources/js/patient-workspace.js';

const tick = () => new Promise((resolve) => setImmediate(resolve));
class Element {
    constructor() { this.events = {}; this.dataset = {}; this.value = ''; this.hidden = false; this.disabled = false; this.textContent = ''; this.children = []; }
    addEventListener(type, fn) { (this.events[type] ??= []).push(fn); }
    emit(type, event = {}) { return Promise.all((this.events[type] ?? []).map((fn) => fn(event))); }
    setAttribute(name, value) { this[name] = value; }
    removeAttribute(name) { delete this[name]; }
    getAttribute(name) { return this[name]; }
    replaceChildren(...nodes) { this.children = nodes; }
    focus() { this.focused = true; }
}

async function harness(run) {
    const originals = { FormData: globalThis.FormData, DOMParser: globalThis.DOMParser };
    const fields = Object.fromEntries(['q', 'perusahaan', 'status', 'sort', 'direction'].map((key) => [key, new Element()]));
    const filter = new Element();
    filter.action = 'https://mcu.test/pasien';
    filter.elements = { namedItem: (key) => fields[key] };
    const skeleton = new Element(), status = new Element(), modalSkeleton = new Element(), modalError = new Element(), retry = new Element(), title = new Element(), body = new Element(), close = new Element();
    skeleton.hidden = modalSkeleton.hidden = true;
    const stats = { replaceWith() {} };
    let results;
    const makeResults = (name, empty = false) => Object.assign(new Element(), { name, replaceWith(next) { results = next; }, querySelector: () => empty ? null : {} });
    results = makeResults('initial');
    const modal = new Element();
    modal.showModal = () => { modal.open = true; };
    modal.close = () => { modal.open = false; };
    let currentForm;
    body.querySelector = () => currentForm;
    const triggers = ['detail', 'edit'].map((action) => Object.assign(new Element(), { dataset: { patientOpen: action, patientId: 'patient', url: `/pasien/identitas/patient${action === 'edit' ? '/edit?modal=1' : ''}` } }));
    const root = new Element();
    root.querySelector = (selector) => ({ '[data-patient-filter]': filter, '[data-loading-skeleton]': skeleton, '[data-patient-workspace-status]': status, '[data-patient-results]': results, '[data-patient-stats]': stats })[selector];
    root.querySelectorAll = () => triggers;
    modal.querySelector = (selector) => ({ '[data-patient-modal-body]': body, '[data-loading-skeleton]': modalSkeleton, '[data-patient-modal-error]': modalError, '[data-patient-modal-retry]': retry, '[data-patient-modal-title]': title })[selector];
    modal.querySelectorAll = () => [close, ...(currentForm?.controls ?? [])];
    const document = new Element();
    document.querySelector = (selector) => selector === '[data-patient-workspace]' ? root : modal;
    const timers = new Map(), requests = [], revoked = [];
    let timerId = 0;
    const window = new Element();
    Object.assign(window, {
        location: { origin: 'https://mcu.test', href: 'https://mcu.test/pasien' },
        history: { replaceState: (_, __, url) => { window.location.href = String(url); } },
        setTimeout: (fn, ms) => { timers.set(++timerId, { fn, ms }); return timerId; },
        clearTimeout: (id) => timers.delete(id),
        fetch: (url, options) => new Promise((resolve, reject) => requests.push({ url, options, resolve, reject })),
        confirm: () => true,
        URL: { createObjectURL: () => 'blob:new-photo', revokeObjectURL: (url) => revoked.push(url) },
    });
    globalThis.FormData = class {
        constructor(form) { this.entries = form === filter ? Object.entries(fields).map(([key, field]) => [key, field.value]) : form.controls.filter((control) => control.name && !control.disabled).map((control) => [control.name, control.files?.[0] ?? control.value]); }
        [Symbol.iterator]() { return this.entries[Symbol.iterator](); }
    };
    globalThis.DOMParser = class { parseFromString(page) { return page; } };
    setupPatientWorkspace(document, window);
    const flush = () => { for (const [id, timer] of timers) if (timer.ms === 300) { timers.delete(id); timer.fn(); } };
    const search = (value) => { fields.q.value = value; fields.q.emit('input', {}); };
    const click = (trigger) => root.emit('click', { target: { closest: (selector) => selector === '[data-patient-open]' ? trigger : null } });
    const respondList = async (index, name, empty = false) => {
        requests[index].resolve({ ok: true, text: async () => ({ querySelector: (selector) => selector === '[data-patient-results]' ? makeResults(name, empty) : stats }) });
        await tick();
    };
    const respondModal = async (index, form = null) => {
        currentForm = form;
        requests[index].resolve({ ok: true, text: async () => ({ querySelector: () => new Element() }) });
        await tick();
    };
    const makeForm = () => {
        const form = new Element(), input = new Element(), preview = new Element(), placeholder = new Element(), reset = new Element(), feedback = new Element(), save = new Element(), name = new Element();
        input.name = 'foto_pasien'; input.files = [];
        name.name = 'nama'; name.value = 'Draft pasien';
        preview.src = '/foto-lama';
        form.action = '/pasien/identitas/patient';
        form.controls = [name, input, save];
        form.querySelector = (selector) => ({ '[data-patient-photo-input]': input, '[data-patient-photo-preview]': preview, '[data-patient-photo-placeholder]': placeholder, '[data-patient-photo-reset]': reset, '[data-patient-form-status]': feedback, '[data-patient-save]': save })[selector];
        return { form, input, preview, placeholder, reset, feedback, save, name };
    };
    try { await run({ fields, filter, skeleton, modalSkeleton, status, modal, modalError, retry, body, requests, triggers, click, search, flush, respondList, respondModal, makeForm, root, window, revoked, get results() { return results; } }); }
    finally { Object.assign(globalThis, originals); }
}

test('patient search debounces and sorting is immediate without replacing focused filter inputs', async () => {
    await harness(async (h) => {
        const input = h.fields.q;
        h.search('Ich'); h.search('Ichwan');
        assert.equal(h.requests.length, 0);
        h.flush();
        assert.equal(h.skeleton.hidden, false);
        assert.equal(h.results.hidden, true);
        await h.respondList(0, 'filtered');
        assert.equal(h.skeleton.hidden, true);
        assert.equal(h.fields.q, input);
        assert.equal(h.fields.q.value, 'Ichwan');
        h.fields.sort.value = 'nama'; h.fields.direction.value = 'asc';
        h.filter.emit('change', { target: { tagName: 'SELECT' } });
        assert.equal(h.requests.length, 2);
        assert.equal(new URL(h.requests[1].url).searchParams.get('sort'), 'nama');
        assert.equal(new URL(h.requests[1].url).searchParams.get('q'), 'Ichwan');
        await h.respondList(1, 'sorted');
        assert.equal(h.results.name, 'sorted');
    });
});

test('older patient responses are ignored while a newer search is waiting for debounce', async () => {
    await harness(async (h) => {
        h.search('old'); h.flush(); h.search('new');
        assert.equal(h.requests[0].options.signal.aborted, true);
        await h.respondList(0, 'stale');
        assert.equal(h.results.name, 'initial');
        h.flush(); await h.respondList(1, 'newest');
        assert.equal(h.results.name, 'newest');
    });
});

test('detail modal opens with skeleton, loads content without navigation, and ignores responses after close', async () => {
    await harness(async (h) => {
        await h.click(h.triggers[0]);
        assert.equal(h.modal.open, true);
        assert.equal(h.modalSkeleton.hidden, false);
        await h.respondModal(0);
        assert.equal(h.body.children.length, 1);
        assert.equal(h.modalSkeleton.hidden, true);
        assert.equal(h.window.location.href, 'https://mcu.test/pasien');
        await h.modal.emit('cancel', { preventDefault() {} });
        await h.click(h.triggers[0]);
        await h.modal.emit('cancel', { preventDefault() {} });
        assert.equal(h.requests[1].options.signal.aborted, true);
        await h.respondModal(1);
        assert.equal(h.modal.open, false);
        assert.equal(h.body.children.length, 0);
        assert.equal(h.triggers[0].focused, true);
    });
});

test('network errors remove skeleton and provide a retry inside the same modal', async () => {
    await harness(async (h) => {
        await h.click(h.triggers[0]);
        h.requests[0].reject(new Error('offline'));
        await tick();
        assert.equal(h.modal.open, true);
        assert.equal(h.modalSkeleton.hidden, true);
        assert.equal(h.retry.hidden, false);
        assert.equal(h.modalError.textContent, 'offline');
    });
});

test('edit photo previews locally and invalid replacements clear the file without losing text', async () => {
    await harness(async (h) => {
        const editor = h.makeForm();
        await h.click(h.triggers[1]); await h.respondModal(0, editor.form);
        editor.input.files = [{ type: 'image/png', size: 100 }];
        await editor.input.emit('change');
        assert.equal(editor.preview.src, 'blob:new-photo');
        assert.equal(editor.preview.hidden, false);
        editor.input.files = [{ type: 'image/svg+xml', size: 100 }];
        await editor.input.emit('change');
        assert.equal(editor.input.value, '');
        assert.equal(editor.preview.src, '/foto-lama');
        assert.equal(editor.name.value, 'Draft pasien');
        assert.match(editor.feedback.textContent, /maksimal 5 MB/);
        assert.deepEqual(h.revoked, ['blob:new-photo']);
    });
});

test('edit submission captures the photo before disabling controls and retains drafts on validation failure', async () => {
    await harness(async (h) => {
        const editor = h.makeForm();
        await h.click(h.triggers[1]); await h.respondModal(0, editor.form);
        const file = { type: 'image/jpeg', size: 100 };
        editor.input.files = [file];
        const save = editor.form.emit('submit', { preventDefault() {} });
        assert.equal(new Map(h.requests[1].options.body).get('foto_pasien'), file);
        assert.equal(editor.input.disabled, true);
        await editor.form.emit('submit', { preventDefault() {} });
        assert.equal(h.requests.length, 2);
        h.requests[1].resolve({ ok: false, headers: { get: () => 'application/json' }, json: async () => ({ errors: { nama: ['Nama wajib diisi.'] } }) });
        await save;
        assert.equal(h.modal.open, true);
        assert.equal(editor.feedback.textContent, 'Nama wajib diisi.');
        assert.equal(editor.name.value, 'Draft pasien');
        assert.equal(editor.input.files[0], file);
        assert.equal(editor.save.disabled, false);
    });
});

test('successful edit refreshes the table then closes the modal without leaving the list', async () => {
    await harness(async (h) => {
        const editor = h.makeForm();
        await h.click(h.triggers[1]); await h.respondModal(0, editor.form);
        const save = editor.form.emit('submit', { preventDefault() {} });
        h.requests[1].resolve({ ok: true, headers: { get: () => 'application/json' }, json: async () => ({ message: 'Data tersimpan.' }) });
        await tick();
        await h.respondList(2, 'updated patient');
        await save;
        assert.equal(h.results.name, 'updated patient');
        assert.equal(h.modal.open, false);
        assert.match(h.status.textContent, /Data tersimpan/);
        assert.equal(h.window.location.href, 'https://mcu.test/pasien');
    });
});
