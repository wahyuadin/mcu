import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../resources/js/patient-photo.js', import.meta.url), 'utf8');

function setup({ modal = false, errors = false } = {}) {
    let stoppedTracks = 0;
    const elements = new Map();
    function element(selector) {
        if (elements.has(selector)) return elements.get(selector);
        const listeners = new Map();
        const node = {
            disabled: selector === '[data-photo-save]', files: [], textContent: 'Simpan foto',
            classList: { toggle() {}, add() {}, remove() {} },
            getAttribute: () => '/private/saved-photo', removeAttribute() {},
            addEventListener: (type, listener) => listeners.set(type, listener),
            dispatch: (type, event = {}) => listeners.get(type)?.(event),
        };
        elements.set(selector, node);
        return node;
    }
    const form = element('form');
    form.querySelector = element;
    const dialog = element('dialog');
    dialog.open = false;
    dialog.showModal = () => { dialog.open = true; };
    dialog.close = () => { dialog.open = false; dialog.dispatch('close'); };
    dialog.animate = () => {
        let callback = null;
        return {
            set onfinish(fn) {
                callback = fn;
                fn();
            },
            get onfinish() {
                return callback;
            },
        };
    };
    dialog.hasAttribute = () => errors;
    dialog.querySelectorAll = () => [element('[data-photo-modal-close]')];
    form.closest = () => modal ? dialog : null;
    const document = element('document');
    document.body = element('body');
    document.querySelector = (selector) => selector === '[data-patient-photo]' ? form : element(selector);
    document.createElement = () => ({
        getContext: () => ({ drawImage() {} }),
        toBlob: (callback) => callback({ type: 'image/webp', size: 512 }),
    });
    const window = element('window');
    window.isSecureContext = true;
    window.matchMedia = () => ({ matches: false });
    class Image {
        naturalWidth = 640;
        naturalHeight = 480;
        set src(value) { this.onload(); }
    }
    class File {
        constructor(parts, name, options) {
            this.name = name;
            this.type = options.type;
            this.size = parts[0].size;
        }
    }
    const video = element('[data-camera]');
    video.videoWidth = 640;
    video.videoHeight = 480;
    video.play = async () => {};
    runInNewContext(source, {
        document, window, Image, File,
        navigator: { mediaDevices: { getUserMedia: async () => ({ getTracks: () => [{ stop() { stoppedTracks += 1; } }] }) } },
        URL: { createObjectURL: () => 'blob:new-photo', revokeObjectURL() {} },
    });
    return { form, document, window, element, dialog, stoppedTracks: () => stoppedTracks };
}

test('replacement upload submits the selected file without DataTransfer', async () => {
    const { form, element } = setup();
    const file = { name: 'new.png', type: 'image/png', size: 1024 };
    element('[data-photo-file]').files = [file];
    await element('[data-photo-file]').dispatch('change');
    assert.equal(element('[data-photo-save]').disabled, false);
    assert.equal(element('[data-photo-preview]').src, 'blob:new-photo');
    const payload = new Map([['foto_pasien', 'previous file']]);
    form.dispatch('submit', { preventDefault: () => assert.fail('Valid replacement must submit') });
    form.dispatch('formdata', { formData: payload });
    assert.equal(payload.get('foto_pasien'), file);
});

test('pasted image replaces the native file input in the multipart payload', async () => {
    const { document, form, element } = setup();
    const file = { name: 'clipboard.png', type: 'image/png', size: 2048 };
    document.dispatch('paste', {
        clipboardData: { items: [{ kind: 'file', type: file.type, getAsFile: () => file }] },
        preventDefault() {},
    });
    await Promise.resolve();
    const payload = new Map();
    form.dispatch('formdata', { formData: payload });
    assert.equal(payload.get('foto_pasien'), file);
    assert.equal(element('[data-photo-save]').disabled, false);
});

test('camera capture sends a WebP file when replacing a stored photo', async () => {
    const { form, element } = setup();
    await element('[data-camera-start]').dispatch('click');
    assert.equal(element('[data-camera-capture]').disabled, false);
    element('[data-camera-capture]').dispatch('click');
    await Promise.resolve();
    const payload = new Map();
    form.dispatch('formdata', { formData: payload });
    assert.equal(payload.get('foto_pasien').type, 'image/webp');
    assert.match(payload.get('foto_pasien').name, /^kamera-.*\.webp$/);
    assert.equal(element('[data-photo-save]').disabled, false);
});

test('returning through browser back restores the save button', async () => {
    const { window, form, element } = setup();
    element('[data-photo-file]').files = [{ name: 'camera.webp', type: 'image/webp', size: 512 }];
    await element('[data-photo-file]').dispatch('change');
    form.dispatch('submit', { preventDefault: () => assert.fail('Valid photo must submit') });
    assert.equal(element('[data-photo-save]').disabled, true);
    window.dispatch('pagehide');
    window.dispatch('pageshow', { persisted: true });
    assert.equal(element('[data-photo-save]').disabled, false);
    assert.equal(element('[data-photo-save]').textContent, 'Simpan foto');
});

test('an oversized replacement cannot submit the previously selected photo', async () => {
    const { form, element } = setup();
    element('[data-photo-file]').files = [{ name: 'large.png', type: 'image/png', size: 5 * 1024 * 1024 + 1 }];
    await element('[data-photo-file]').dispatch('change');
    assert.equal(element('[data-photo-save]').disabled, true);
    let prevented = false;
    form.dispatch('submit', { preventDefault: () => { prevented = true; } });
    assert.equal(prevented, true);
});

test('photo button opens modal and closing stops the active camera', async () => {
    const app = setup({ modal: true });
    app.element('[data-photo-modal-open]').dispatch('click');
    assert.equal(app.dialog.open, true);
    await app.element('[data-camera-start]').dispatch('click');
    assert.equal(app.stoppedTracks(), 0);
    app.element('[data-photo-modal-close]').dispatch('click');
    assert.equal(app.dialog.open, false);
    assert.equal(app.stoppedTracks(), 1);
    assert.equal(app.element('[data-photo-save]').disabled, true);
});

test('canceling a modal discards the selected replacement and restores stored preview', async () => {
    const app = setup({ modal: true });
    app.element('[data-photo-modal-open]').dispatch('click');
    app.element('[data-photo-file]').files = [{ name: 'new.png', type: 'image/png', size: 512 }];
    await app.element('[data-photo-file]').dispatch('change');
    assert.equal(app.element('[data-photo-save]').disabled, false);
    app.dialog.close();
    assert.equal(app.element('[data-photo-preview]').src, '/private/saved-photo');
    const payload = new Map();
    app.form.dispatch('formdata', { formData: payload });
    assert.equal(payload.has('foto_pasien'), false);
});

test('a closed photo modal ignores clipboard images', async () => {
    const app = setup({ modal: true });
    app.document.dispatch('paste', {
        clipboardData: { items: [{ kind: 'file', type: 'image/png', getAsFile: () => ({ name: 'paste.png', type: 'image/png', size: 512 }) }] },
        preventDefault: () => assert.fail('Closed modal must not intercept paste'),
    });
    await Promise.resolve();
    assert.equal(app.element('[data-photo-save]').disabled, true);
});

test('photo validation errors reopen the modal', () => {
    const app = setup({ modal: true, errors: true });
    assert.equal(app.dialog.open, true);
});
