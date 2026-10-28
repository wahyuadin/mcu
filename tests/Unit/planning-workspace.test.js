import assert from 'node:assert/strict';
import { test } from 'node:test';
import { setupPlanningWorkspace } from '../../resources/js/planning-workspace.js';

const tick = () => new Promise((resolve) => setImmediate(resolve));
class Element {
    constructor() { this.events = {}; this.hidden = false; this.value = ''; this.textContent = ''; this.disabled = false; }
    addEventListener(type, handler) { this.events[type] = handler; }
    setAttribute() {}
    removeAttribute() {}
    focus() { this.focused = true; }
}

async function harness(run) {
    const original = { FormData: globalThis.FormData, DOMParser: globalThis.DOMParser };
    const fields = Object.fromEntries(['q', 'perusahaan', 'status'].map((key) => [key, new Element()]));
    const editFields = Object.fromEntries(['perusahaan_id', 'nomor_kunjungan', 'periode_kunjungan', 'tgl_mulai', 'tgl_selesai', 'jumlah_peserta_direncanakan'].map((key) => [key, new Element()]));
    const filter = new Element();
    filter.action = 'https://mcu.test/rencana-kunjungan';
    filter.elements = { namedItem: (key) => fields[key] };
    const form = new Element();
    form.elements = { namedItem: (key) => editFields[key] };
    const errors = new Element(), close = new Element(), save = new Element(), skeleton = new Element(), status = new Element();
    skeleton.hidden = true;
    const modal = new Element();
    modal.querySelector = (selector) => ({ form, '[data-planning-errors]': errors, '[data-planning-save]': save })[selector];
    modal.querySelectorAll = (selector) => selector === '[data-planning-close]' ? [close] : [close, save, ...Object.values(editFields)];
    modal.showModal = () => { modal.open = true; };
    modal.close = () => { modal.open = false; };
    let result;
    const makeResult = (name) => Object.assign(new Element(), { name, replaceWith(next) { result = next; } });
    result = makeResult('initial');
    const root = new Element();
    const stats = { replaceWith() {} };
    root.querySelector = (selector) => ({ '[data-planning-filter]': filter, '[data-loading-skeleton]': skeleton, '[data-planning-status]': status, '[data-planning-results]': result, '[data-planning-count]': stats, '[data-planning-stats]': stats })[selector];
    root.querySelectorAll = () => [];
    const document = { querySelector: (selector) => selector === '[data-planning-workspace]' ? root : modal };
    const timers = new Map(), calls = [], events = {};
    let timerId = 0;
    const window = {
        location: { href: filter.action, origin: 'https://mcu.test' },
        setTimeout: (fn, ms) => { timers.set(++timerId, { fn, ms }); return timerId; },
        clearTimeout: (id) => timers.delete(id),
        history: { replaceState: (_, __, url) => { window.location.href = String(url); } },
        fetch: (url, options) => new Promise((resolve, reject) => calls.push({ url, options, resolve, reject })),
        addEventListener: (type, fn) => { events[type] = fn; },
    };
    globalThis.FormData = class {
        constructor(target) { this.target = target; }
        *[Symbol.iterator]() { for (const [key, field] of Object.entries(this.target === filter ? fields : editFields)) yield [key, field.value]; }
    };
    globalThis.DOMParser = class { parseFromString(name) { return { querySelector: (selector) => selector === '[data-planning-results]' ? makeResult(name) : stats }; } };
    setupPlanningWorkspace(document, window);
    const search = (value) => { fields.q.value = value; filter.events.input({ target: { name: 'q' } }); };
    const flush = () => { for (const [id, timer] of timers) if (timer.ms === 300) { timers.delete(id); timer.fn(); } };
    const respond = async (index, name) => { calls[index].resolve({ ok: true, text: async () => name }); await tick(); };
    try { await run({ calls, fields, editFields, filter, form, root, modal, errors, skeleton, status, search, flush, respond, window, get result() { return result; } }); }
    finally { Object.assign(globalThis, original); }
}

test('search is debounced, uses skeleton only while loading, and retains the input element', async () => {
    await harness(async (h) => {
        const input = h.fields.q;
        h.search('P'); h.search('PRAKARSA');
        assert.equal(h.calls.length, 0);
        h.flush();
        assert.equal(h.calls.length, 1);
        assert.equal(new URL(h.calls[0].url).searchParams.get('q'), 'PRAKARSA');
        assert.equal(h.skeleton.hidden, false);
        assert.equal(h.result.hidden, true);
        await h.respond(0, 'filtered');
        assert.equal(h.result.name, 'filtered');
        assert.equal(h.skeleton.hidden, true);
        assert.equal(h.fields.q, input);
        assert.equal(h.fields.q.value, 'PRAKARSA');
    });
});

test('typing invalidates pending responses before the debounce starts the next request', async () => {
    await harness(async (h) => {
        h.search('old'); h.flush();
        h.search('new');
        assert.equal(h.calls[0].options.signal.aborted, true);
        await h.respond(0, 'stale');
        assert.equal(h.result.name, 'initial');
        h.flush();
        await h.respond(1, 'latest');
        assert.equal(h.result.name, 'latest');
    });
});

test('dropdown filters load immediately and errors restore the existing list', async () => {
    await harness(async (h) => {
        h.fields.status.value = 'ongoing';
        h.filter.events.change({ target: { tagName: 'SELECT' } });
        assert.equal(h.calls.length, 1);
        assert.equal(new URL(h.calls[0].url).searchParams.get('status'), 'ongoing');
        h.calls[0].reject(new Error('offline'));
        await tick();
        assert.equal(h.result.name, 'initial');
        assert.equal(h.result.hidden, false);
        assert.equal(h.skeleton.hidden, true);
        assert.match(h.status.textContent, /offline/);
    });
});

test('clicking a company pre-fills the modal and validation errors keep the form open', async () => {
    await harness(async (h) => {
        const edit = { dataset: { url: 'https://mcu.test/rencana-kunjungan/visit', plan: JSON.stringify({ perusahaan_id: 'company', nomor_kunjungan: 'PKS-001', periode_kunjungan: 'Oktober 2026', tgl_mulai: '2026-10-28', tgl_selesai: '2026-10-30', jumlah_peserta_direncanakan: 100 }) } };
        h.root.events.click({ target: { closest: () => edit } });
        assert.equal(h.modal.open, true);
        assert.equal(h.editFields.nomor_kunjungan.value, 'PKS-001');
        assert.equal(h.editFields.tgl_selesai.value, '2026-10-30');
        const save = h.form.events.submit({ preventDefault() {} });
        h.calls[0].resolve({ ok: false, headers: { get: () => 'application/json' }, json: async () => ({ errors: { nomor_kunjungan: ['Nomor PKS sudah digunakan.'] } }) });
        await save;
        assert.equal(h.modal.open, true);
        assert.equal(h.editFields.nomor_kunjungan.value, 'PKS-001');
        assert.equal(h.errors.textContent, 'Nomor PKS sudah digunakan.');
        assert.equal(h.errors.focused, true);
    });
});
