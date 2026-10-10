import assert from 'node:assert/strict';
import { test } from 'node:test';
import { setupPatientImport, filterImportRows } from '../../resources/js/patient-import.js';

class Element {
    constructor() { this.events = {}; this.dataset = {}; this.children = []; this.classList = { add() {}, remove() {} }; }
    addEventListener(name, callback) { const previous = this.events[name]; this.events[name] = previous ? (event) => { previous(event); return callback(event); } : callback; }
    focus() { this.focused = true; }
    setCustomValidity(message) { this.validity = message; }
    append(...children) { this.children.push(...children); }
    replaceChildren(...children) { this.children = children; }
    querySelectorAll() { return []; }
    click() { this.clicked = true; }
    remove() {}
}

async function harness(run, { reduced = false, reopen = false } = {}) {
    const originals = { window: globalThis.window, getComputedStyle: globalThis.getComputedStyle, document: globalThis.document, fetch: globalThis.fetch, FormData: globalThis.FormData };
    const form = new Element();
    const file = new Element();
    const save = new Element();
    const status = new Element();
    const trigger = new Element();
    const close = new Element();
    const dialog = new Element();
    const preview = new Element();
    const confirm = new Element();
    const mapSave = new Element();
    const mapping = new Element();
    const drop = new Element();
    const report = new Element();
    const filter = new Element(); filter.value = 'all';
    const search = new Element(); search.value = '';
    const rows = new Element();
    const downloads = [];
    globalThis.document = { createElement: () => new Element(), body: { append: (link) => downloads.push(link) } };
    globalThis.FormData = class {
        constructor(source) {
            this.values = new Map();
            if (source) {
                const input = source.querySelector('[data-patient-import-file]');
                if (!input.disabled && input.files?.[0]) this.values.set('import_file', input.files[0]);
                this.values.set('_token', source.querySelector('[name="_token"]').value);
            }
        }
        get(name) { return this.values.get(name) ?? null; }
    };
    const animations = [];
    const windowEvents = {};
    dialog.hasAttribute = () => reopen;
    dialog.showModal = () => { dialog.open = true; };
    dialog.close = () => { dialog.open = false; };
    dialog.getBoundingClientRect = () => ({ left: 0, right: 100, top: 0, bottom: 100 });
    dialog.animate = (frames, options) => {
        const animation = { frames, options, finished: Promise.resolve(), cancel() {} };
        animations.push(animation);
        return animation;
    };
    form.querySelector = (selector) => ({ '[data-patient-import-file]': file, '[data-patient-import-save]': save, '[name="_token"]': { value: 'csrf-token' } })[selector];
    form.reportValidity = () => !file.validity;
    form.action = '/pasien/import';
    dialog.querySelector = (selector) => ({ '[data-patient-import-form]': form, '[data-patient-import-status]': status, '[data-patient-import-preview]': preview, '[data-patient-import-confirm]': confirm, '[data-patient-import-map-save]': mapSave, '[data-patient-import-mapping]': mapping, '[data-patient-import-drop]': drop, '[data-patient-import-report]': report, '[data-patient-import-filter]': filter, '[data-patient-import-search]': search, '[data-patient-import-rows]': rows })[selector] ?? new Element();
    dialog.querySelectorAll = () => [close];
    globalThis.window = { matchMedia: () => ({ matches: reduced }), addEventListener: (event, callback) => { windowEvents[event] = callback; } };
    globalThis.getComputedStyle = () => ({ opacity: '1', getPropertyValue: () => 'cubic-bezier(0.23, 1, 0.32, 1)' });
    setupPatientImport(dialog, { querySelectorAll: () => [trigger] });
    try {
        await run({ dialog, form, file, save, status, trigger, close, animations, windowEvents, preview, confirm, drop, report, downloads, rows });
    } finally {
        Object.entries(originals).forEach(([name, value]) => {
            if (value === undefined) delete globalThis[name];
            else globalThis[name] = value;
        });
    }
}

test('import dialog fades in and waits for fade out before closing and restoring focus', async () => {
    await harness(async ({ dialog, trigger, close, animations }) => {
        trigger.events.click();
        assert.equal(dialog.open, true);
        assert.deepEqual(animations[0].frames, [{ opacity: 0 }, { opacity: 1 }]);
        assert.equal(animations[0].options.duration, 220);
        let finish;
        dialog.animate = (frames, options) => {
            const animation = { frames, options, finished: new Promise((resolve) => { finish = resolve; }), cancel() {} };
            animations.push(animation);
            return animation;
        };
        const closing = close.events.click();
        assert.equal(dialog.open, true);
        assert.equal(animations.at(-1).options.duration, 200);
        assert.equal(dialog.dataset.closing, '');
        finish();
        await closing;
        assert.equal(dialog.open, false);
        assert.equal(trigger.focused, true);
    });
});

test('validation errors reopen dialog and reduced motion uses gentle opacity fades', async () => {
    await harness(async ({ dialog, animations }) => {
        assert.equal(dialog.open, true);
        assert.equal(animations[0].options.duration, 125);
        let prevented = false;
        dialog.events.cancel({ preventDefault: () => { prevented = true; } });
        await new Promise((resolve) => setImmediate(resolve));
        assert.equal(prevented, true);
        assert.equal(dialog.open, false);
        assert.equal(animations.at(-1).options.duration, 125);
    }, { reopen: true, reduced: true });
});

test('file selection checks xlsx extension and five megabyte size without losing the selected file', async () => {
    await harness(({ file, status }) => {
        file.files = [{ name: 'bad.csv', size: 100 }];
        file.events.change();
        assert.match(file.validity, /XLSX/);
        file.files = [{ name: 'large.xlsx', size: 5 * 1024 * 1024 + 1 }];
        file.events.change();
        assert.match(file.validity, /5 MB/);
        file.files = [{ name: 'PASIEN.XLSX', size: 5 * 1024 * 1024 }];
        file.events.change();
        assert.equal(file.validity, '');
        assert.equal(status.textContent, 'File dipilih: PASIEN.XLSX');
    });
});

test('submission blocks duplicate clicks and closure while browser back restores controls', async () => {
    await harness(async ({ dialog, form, trigger, close, save, windowEvents }) => {
        globalThis.fetch = () => new Promise(() => {});
        trigger.events.click();
        form.events.submit({ preventDefault() {} });
        assert.equal(save.disabled, true);
        let prevented = false;
        form.events.submit({ preventDefault() { prevented = true; } });
        assert.equal(prevented, true);
        await close.events.click();
        assert.equal(dialog.open, true);
        windowEvents.pageshow();
        assert.equal(save.disabled, false);
        assert.equal(close.disabled, false);
        await close.events.click();
        assert.equal(dialog.open, false);
    });
});

const tick = () => new Promise((resolve) => setImmediate(resolve));
const sample = (status = 'preview') => ({ id: 'batch', status, headers: ['nama_karyawan'], mapping: { nama_karyawan: 'nama_karyawan' }, version: 0, confirm_url: '/confirm', report_url: status === 'complete' ? '/report.xlsx' : null, stats: { ready: status === 'preview' ? 1 : 0, failed: 1, succeeded: status === 'complete' ? 1 : 0, existing: 0, total: 2 }, rows: [{ number: 2, data: { nama_karyawan: '<script>Nama</script>' }, status: status === 'preview' ? 'ready' : 'succeeded', errors: [], visit: 'Kunjungan', package: 'Paket', nomor_mcu: null }, { number: 3, data: { nama_karyawan: 'Gagal' }, status: 'failed', errors: ['Jenis kelamin wajib'], visit: null, package: null, nomor_mcu: null }] });

for (const selection of ['picker', 'drop']) {
    test(`${selection} upload includes the selected file before disabling controls`, async () => {
        await harness(async ({ form, file, drop, preview }) => {
            const selected = { name: 'pasien.xlsx', size: 1024 };
            if (selection === 'drop') {
                drop.events.drop({ preventDefault() {}, dataTransfer: { files: [selected] } });
            } else {
                file.files = [selected];
                file.events.change();
            }
            const calls = [];
            globalThis.fetch = async (url, options) => {
                calls.push({ url, options });
                return { ok: true, json: async () => sample() };
            };
            form.events.submit({ preventDefault() {} });
            assert.equal(file.disabled, true);
            await tick();
            assert.equal(calls.length, 1);
            assert.equal(calls[0].options.body.get('import_file'), selected);
            assert.equal(calls[0].options.body.get('_token'), 'csrf-token');
            assert.equal(file.disabled, false);
            assert.equal(preview.hidden, false);
        });
    });
}

test('upload stages a safe text preview and only confirmation imports then downloads failed results', async () => {
    await harness(async ({ form, preview, confirm, report, downloads, rows }) => {
        const calls = [];
        globalThis.fetch = async (url, options) => { calls.push({ url, options }); return { ok: true, json: async () => sample(url === '/confirm' ? 'complete' : 'preview') }; };
        form.events.submit({ preventDefault() {} });
        await tick();
        assert.equal(calls.length, 1);
        assert.equal(calls[0].url, '/pasien/import');
        assert.equal(calls[0].options.headers['X-CSRF-TOKEN'], 'csrf-token');
        assert.equal(preview.hidden, false);
        assert.equal(confirm.textContent, 'Import 1 pasien valid');
        assert.equal(rows.children[0].children[1].textContent, '<script>Nama</script>');
        assert.equal(downloads.length, 0);
        confirm.events.click();
        await tick();
        assert.equal(calls.length, 2);
        assert.equal(calls[1].options.body, '{"version":0}');
        assert.equal(confirm.hidden, true);
        assert.equal(report.href, '/report.xlsx');
        assert.equal(report.hidden, false);
        assert.equal(downloads.length, 1);
        assert.equal(downloads[0].clicked, true);
    });
});

test('drop selects exactly one file and replacement hides old preview', async () => {
    await harness(({ drop, file, preview, status }) => {
        drop.events.drop({ preventDefault() {}, dataTransfer: { files: [{ name: 'pasien.xlsx', size: 100 }] } });
        assert.equal(file.files[0].name, 'pasien.xlsx');
        assert.equal(preview.hidden, true);
        drop.events.drop({ preventDefault() {}, dataTransfer: { files: [{}, {}] } });
        assert.equal(status.textContent, 'Pilih satu file XLSX.');
        assert.equal(file.files[0].name, 'pasien.xlsx');
    });
});

test('network errors leave preview retryable and restore controls', async () => {
    await harness(async ({ form, save, close, status }) => {
        globalThis.fetch = async () => { throw new Error('Koneksi gagal'); };
        form.events.submit({ preventDefault() {} });
        await tick();
        assert.equal(save.disabled, false);
        assert.equal(close.disabled, false);
        assert.equal(save.textContent, 'Upload Data');
        assert.equal(status.textContent, 'Koneksi gagal');
    });
});

test('crosscheck filters and searches names placements errors and MCU numbers', () => {
    const rows = sample().rows;
    assert.equal(filterImportRows(rows, 'valid').length, 1);
    assert.equal(filterImportRows(rows, 'failed').length, 1);
    assert.equal(filterImportRows(rows, 'all', '  paket ').length, 1);
    assert.equal(filterImportRows(rows, 'failed', 'kelamin').length, 1);
    assert.equal(filterImportRows(rows, 'valid', 'kelamin').length, 0);
});
