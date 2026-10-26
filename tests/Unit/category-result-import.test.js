import { test } from 'node:test';
import assert from 'node:assert/strict';
import { initializeCategoryImport, validateResultFile } from '../../resources/js/category-result-import.js';

function node() {
    return {
        events: {}, children: [], value: '', hidden: false, disabled: false, textContent: '', attributes: {},
        classList: { add() {}, remove() {} },
        addEventListener(name, fn) { (this.events[name] ??= []).push(fn); },
        async fire(name, event = {}) { for (const fn of this.events[name] || []) await fn({ preventDefault() {}, ...event }); },
        append(...children) { this.children.push(...children); },
        replaceChildren(...children) { this.children = children; },
        setAttribute(name, value) { this.attributes[name] = value; },
        removeAttribute(name) { delete this.attributes[name]; },
        focus() { this.focused = true; },
    };
}

function setup(reduced = false, delegated = false) {
    const names = ['result-import-form', 'import-category', 'result-import-file', 'result-upload', 'result-confirm', 'result-import-status', 'result-template', 'result-preview', 'result-preview-rows', 'result-progress-wrap', 'result-progress', 'result-progress-text', 'result-page', 'result-prev', 'result-next', 'result-summary', 'result-report', 'result-file-name', 'import-visit', 'result-dropzone'];
    const elements = Object.fromEntries(names.map((name) => [name, node()]));
    const closeButton = node(); const trigger = node(); trigger.dataset = { categoriesUrl: '/visit/categories', visitName: 'MCU-001' };
    const token = { value: 'csrf-token' }; elements['result-import-form'].querySelector = () => token;
    const animations = [], requests = [];
    const dialog = {
        ...node(), open: false, querySelector: (selector) => elements[selector.slice(6, -1)], querySelectorAll: () => [closeButton],
        showModal() { this.open = true; }, close() { this.open = false; },
        animate(frames, options) { animations.push({ frames, options }); return { finished: Promise.resolve(), cancel() {} }; },
    };
    const window = {
        matchMedia: () => ({ matches: reduced }), getComputedStyle: () => ({ opacity: '1', getPropertyValue: () => '' }), AbortController,
        Option: class { constructor(text, value) { this.textContent = text; this.value = value; } },
        FormData: class { constructor() { this.fields = new Map(); } append(key, value) { this.fields.set(key, value); } },
        XMLHttpRequest: class {
            constructor() { this.upload = {}; this.headers = {}; requests.push(this); }
            open(method, url) { this.method = method; this.url = url; }
            setRequestHeader(name, value) { this.headers[name] = value; }
            send(body) { this.body = body; }
            respond(data, status = 200) { this.status = status; this.responseText = JSON.stringify(data); this.onload(); }
        },
        fetch: async () => ({ ok: true, redirected: false, json: async () => ({ categories: [{ id: 'cat-1', name: 'Kategori <script>', template_url: '/template', upload_url: '/upload' }] }) }),
    };
    const document = { ...(delegated ? node() : {}), createElement: () => node() };
    const api = initializeCategoryImport(dialog, [trigger], window, document);
    const open = async () => { await trigger.fire('click'); elements['import-category'].value = 'cat-1'; await elements['import-category'].fire('change'); };
    return { dialog, trigger, closeButton, elements, window, document, requests, animations, api, open };
}

const file = { name: 'hasil.xlsx', size: 1000 };
const payload = (changes = []) => ({ id: 'batch', digest: 'digest', status: 'preview', confirm_url: '/confirm', report_url: '/report', stats: { total: 1, ready: 1, failed: 0, skipped: 0, succeeded: 0 }, rows: [{ number: 2, name: 'Pasien <img>', nomor_mcu: 'MCU-1', status: 'ready', retained: 0, errors: [], changes }] });

test('file validation accepts XLSX and rejects wrong extensions and files above five MB', () => {
    assert.equal(validateResultFile(file), null);
    assert.equal(validateResultFile({ name: 'HASIL.XLSX', size: 1000 }), null);
    assert.match(validateResultFile({ name: 'hasil.csv', size: 10 }), /XLSX/);
    assert.match(validateResultFile({ ...file, size: 5 * 1024 * 1024 + 1 }), /5 MB/);
});

test('an active category trigger locks its category and loads the whole-visit template without another selector', async () => {
    const ui = setup();
    ui.trigger.dataset.categoryId = 'cat-1';
    await ui.trigger.fire('click');
    assert.equal(ui.elements['import-category'].value, 'cat-1');
    assert.equal(ui.elements['import-category'].hidden, true);
    assert.equal(ui.elements['result-template'].href, '/template');
    assert.match(ui.elements['result-import-status'].textContent, /seluruh peserta kunjungan/);
    ui.api.acceptFile(file);
    assert.equal(ui.elements['result-upload'].disabled, false);
});

test('delegated opening still works for an import button inserted after an active-tab refresh', async () => {
    const ui = setup(false, true);
    const replacement = node();
    replacement.dataset = { categoryId: 'cat-1', categoriesUrl: '/visit/categories', visitName: 'Kategori tab baru' };
    await ui.document.fire('click', { target: { closest: () => replacement } });
    await new Promise((resolve) => setImmediate(resolve));
    assert.equal(ui.dialog.open, true);
    assert.equal(ui.elements['import-category'].value, 'cat-1');
    assert.equal(ui.elements['import-visit'].textContent, 'Kategori tab baru');
    await ui.api.close();
    assert.equal(replacement.focused, true);
});

test('categories and template are loaded dynamically and dialog waits for fade-out before restoring focus', async () => {
    const ui = setup(); await ui.open();
    assert.equal(ui.dialog.open, true);
    assert.equal(ui.elements['result-template'].href, '/template');
    assert.equal(ui.elements['import-category'].children[1].textContent, 'Kategori <script>');
    let finish;
    ui.dialog.animate = () => ({ finished: new Promise((resolve) => { finish = resolve; }), cancel() {} });
    const pending = ui.api.close();
    assert.equal(ui.dialog.open, true);
    finish(); await pending;
    assert.equal(ui.dialog.open, false);
    assert.equal(ui.trigger.focused, true);
});

test('drop upload sends the actual file with CSRF and real progress, blocking duplicate requests and closure', async () => {
    const ui = setup(); await ui.open();
    await ui.elements['result-dropzone'].fire('drop', { dataTransfer: { files: [file] } });
    const pending = ui.elements['result-import-form'].fire('submit');
    const xhr = ui.requests[0];
    assert.equal(xhr.body.fields.get('import_file'), file);
    assert.equal(xhr.headers['X-CSRF-TOKEN'], 'csrf-token');
    assert.equal(ui.closeButton.disabled, true);
    await ui.api.close(); assert.equal(ui.dialog.open, true);
    await ui.elements['result-import-form'].fire('submit'); assert.equal(ui.requests.length, 1);
    xhr.upload.onprogress({ lengthComputable: true, loaded: 80, total: 100 });
    assert.equal(ui.elements['result-progress'].value, 80);
    assert.equal(ui.elements['result-progress-text'].textContent, 'Mengupload 80%');
    xhr.respond(payload([{ label: 'Temuan', value: '<script>jawaban</script>' }])); await pending;
    assert.equal(ui.elements['result-confirm'].disabled, false);
    assert.match(ui.elements['result-preview-rows'].children[0].children[2].textContent, /<script>jawaban<\/script>/);
});

test('network failures retain the file for retry and confirmation only posts after preview', async () => {
    const ui = setup(); await ui.open(); ui.api.acceptFile(file);
    const pending = ui.elements['result-import-form'].fire('submit');
    ui.requests[0].onerror(); await pending;
    assert.equal(ui.elements['result-upload'].disabled, false);
    assert.equal(ui.elements['result-confirm'].disabled, true);
    const retry = ui.elements['result-import-form'].fire('submit');
    assert.equal(ui.requests[1].body.fields.get('import_file'), file);
    ui.requests[1].respond(payload()); await retry;
    const confirm = ui.elements['result-confirm'].fire('click');
    assert.deepEqual(JSON.parse(ui.requests[2].body), { digest: 'digest', confirmed: 1 });
    await ui.elements['result-confirm'].fire('click'); assert.equal(ui.requests.length, 3);
    ui.requests[2].respond({ ...payload(), status: 'complete', stats: { total: 1, ready: 0, failed: 0, skipped: 0, succeeded: 1 } }); await confirm;
    assert.equal(ui.elements['result-confirm'].disabled, true);
});

test('reduced motion uses gentle opacity fades and changing category discards stale previews', async () => {
    const ui = setup(true); await ui.open();
    assert.equal(ui.animations[0].options.duration, 125);
    assert.deepEqual(ui.animations[0].frames, [{ opacity: 0 }, { opacity: 1 }]);
    ui.api.acceptFile(file);
    const pending = ui.elements['result-import-form'].fire('submit'); ui.requests[0].respond(payload()); await pending;
    await ui.elements['import-category'].fire('change');
    assert.equal(ui.elements['result-preview'].hidden, true);
    assert.equal(ui.elements['result-confirm'].disabled, true);
    assert.equal(ui.elements['result-upload'].disabled, true);
});
