import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../resources/js/examination-fields.js', import.meta.url), 'utf8').split("if (typeof document")[0].replace(/^export /gm, '') + readFileSync(new URL('../../resources/js/public-anamnesa.js', import.meta.url), 'utf8').replace(/^import .*;\n/gm, '').replace(/^export /gm, '');

function setup() {
    const controls = [];
    const node = () => ({ events: {}, children: [], hidden: false, textContent: '', addEventListener(name, fn) { this.events[name] = fn; }, append(...children) { this.children.push(...children); }, replaceChildren() { this.children = []; }, scrollIntoView() {} });
    const field = (value, valid = true) => ({ value, valid, checkValidity() { return this.valid; }, reportValidity() { this.reported = true; } });
    const answer = field('Tidak ada keluhan'); answer.name = 'answers[keluhan][answer]';
    const note = field('<script>catatan</script>'); note.name = 'answers[keluhan][note]';
    const choice = field('Tidak');
    const consent = field('1', false);
    const sections = [[answer, note], [choice], [consent]].map((fields) => ({ ...node(), querySelectorAll: () => fields }));
    const cards = [{ dataset: { label: 'Keluhan' }, querySelector: (selector) => selector === '[data-answer-input]' ? answer : (selector === 'textarea' ? note : null), querySelectorAll: () => [note] }, { dataset: { label: 'Hepatitis' }, querySelector: (selector) => selector.includes('radio') ? choice : null, querySelectorAll: () => [] }];
    const back = node(); const next = node(); const submit = node(); const progress = node(); const summary = node();
    const selectors = { '[data-form-back]': back, '[data-form-next]': next, '[data-form-submit]': submit, '[data-form-progress]': progress, '[data-review-answers]': summary };
    const form = { ...node(), querySelector: (selector) => selectors[selector], querySelectorAll: (selector) => selector.includes('section') ? sections : (selector === '[data-answer-card]' ? cards : []) };
    const window = { ...node() };
    const document = { createElement: () => node(), querySelector: () => null, querySelectorAll: () => controls };
    const context = { document, window, navigator: {} };
    runInNewContext(source, context);
    context.initializePublicAnamnesa(form);
    const event = () => ({ prevented: false, preventDefault() { this.prevented = true; } });
    return { form, sections, back, next, submit, progress, summary, answer, consent, event, window };
}

test('section navigation validates current answers and preserves values when moving back', () => {
    const ui = setup();
    assert.equal(ui.sections[0].hidden, false);
    assert.equal(ui.sections[1].hidden, true);
    assert.equal(ui.back.hidden, true);
    assert.equal(ui.submit.hidden, true);
    ui.answer.valid = false;
    ui.next.events.click();
    assert.equal(ui.answer.reported, true);
    assert.equal(ui.sections[0].hidden, false);
    ui.answer.valid = true;
    ui.next.events.click();
    assert.equal(ui.progress.textContent, 'Bagian 2 dari 3');
    ui.back.events.click();
    assert.equal(ui.answer.value, 'Tidak ada keluhan');
    assert.equal(ui.sections[0].hidden, false);
});

test('review renders safe text and consent is required before submission', () => {
    const ui = setup();
    ui.next.events.click(); ui.next.events.click();
    assert.equal(ui.submit.hidden, false);
    assert.equal(ui.summary.children.length, 2);
    assert.equal(ui.summary.children[0].children[1].textContent, 'Tidak ada keluhan — <script>catatan</script>');
    const denied = ui.event(); ui.form.events.submit(denied);
    assert.equal(denied.prevented, true);
    assert.equal(ui.consent.reported, true);
    ui.consent.valid = true;
    const accepted = ui.event(); ui.form.events.submit(accepted);
    assert.equal(accepted.prevented, false);
    assert.equal(ui.submit.disabled, true);
    ui.window.events.pageshow();
    assert.equal(ui.submit.disabled, false);
});

test('enter cannot skip review and unsaved answers warn before leaving', () => {
    const ui = setup();
    ui.consent.valid = true;
    ui.form.events.input();
    const leave = ui.event(); ui.window.events.beforeunload(leave);
    assert.equal(leave.prevented, true);
    const enter = ui.event(); ui.form.events.submit(enter);
    assert.equal(enter.prevented, true);
    assert.equal(ui.submit.hidden, false);
    assert.equal(ui.progress.textContent, 'Bagian 3 dari 3');
});

test('pages without public anamnesa controls are unaffected', () => {
    assert.doesNotThrow(() => runInNewContext(source, { document: { querySelector: () => null, querySelectorAll: () => [] } }));
});

test('barcode modal fades in and waits for fade out before closing and restoring focus', async () => {
    const events = {};
    const animations = [];
    let finishExit;
    let focused = false;
    const trigger = { focus() { focused = true; } };
    const dialog = {
        open: false,
        addEventListener(name, fn) { events[name] = fn; },
        showModal() { this.open = true; }, close() { this.open = false; },
        animate(frames, options) {
            animations.push({ frames, options });
            return { cancel() {}, finished: options.duration === 200 ? new Promise((resolve) => { finishExit = resolve; }) : Promise.resolve() };
        },
    };
    const form = { addEventListener(name, fn) { this[name] = fn; }, querySelector: () => trigger };
    const close = { addEventListener(name, fn) { this[name] = fn; } };
    const context = { document: { querySelector: () => null, querySelectorAll: () => [] }, window: { matchMedia: () => ({ matches: false }) }, getComputedStyle: () => ({ opacity: '0.6', getPropertyValue: () => '' }) };
    runInNewContext(source, context);
    context.initializeBarcodeModal(dialog, [form], close);
    form.submit({ preventDefault() {} });
    assert.equal(form.target, 'anamnesa-barcode-frame');
    assert.equal(dialog.open, true);
    assert.equal(animations[0].options.duration, 220);
    assert.equal(animations[0].frames[0].opacity, 0);
    const closing = close.click();
    assert.equal(dialog.open, true);
    assert.equal(animations[1].options.duration, 200);
    assert.equal(animations[1].frames[0].opacity, '0.6');
    finishExit(); await closing;
    assert.equal(dialog.open, false);
    assert.equal(focused, true);
});

test('Escape uses a gentle reduced-motion fade and repeated submit is blocked', async () => {
    const events = {};
    const durations = [];
    const dialog = { open: false, addEventListener(name, fn) { events[name] = fn; }, showModal() { this.open = true; }, close() { this.open = false; }, animate(frames, options) { durations.push(options.duration); return { cancel() {}, finished: Promise.resolve() }; } };
    const form = { addEventListener(name, fn) { this[name] = fn; }, querySelector: () => ({ focus() {} }) };
    const context = { document: { querySelector: () => null, querySelectorAll: () => [] }, window: { matchMedia: () => ({ matches: true }) }, getComputedStyle: () => ({ opacity: '1', getPropertyValue: () => '' }) };
    runInNewContext(source, context);
    context.initializeBarcodeModal(dialog, [form], null);
    form.submit({ preventDefault() {} });
    let blocked = false;
    form.submit({ preventDefault() { blocked = true; } });
    assert.equal(blocked, true);
    let prevented = false;
    events.cancel({ preventDefault() { prevented = true; } });
    await Promise.resolve(); await Promise.resolve(); await Promise.resolve();
    assert.equal(prevented, true);
    assert.deepEqual(durations, [125, 125]);
    assert.equal(dialog.open, false);
});

test('free tags preserve entered labels, reject duplicates and keep no-complaint selection exclusive', () => {
    const context = { document: { querySelector: () => null, querySelectorAll: () => [] } };
    runInNewContext(source, context);
    const add = context.normalizeComplaintTags;
    assert.deepEqual(Array.from(add([], ' Pusing ')), ['Pusing']);
    assert.deepEqual(Array.from(add(['Pusing'], 'pusing')), ['Pusing']);
    assert.deepEqual(Array.from(add(['Pusing'], 'Tidak ada keluhan')), ['Tidak ada keluhan']);
    assert.deepEqual(Array.from(add(['Tidak ada keluhan'], 'Nyeri leher')), ['Nyeri leher']);
    assert.throws(() => add([], 'A'.repeat(101)), /100 karakter/);
    assert.throws(() => add(Array.from({ length: 20 }, (_, index) => `Keluhan ${index}`), 'Keluhan baru'), /20 keluhan/);
});

test('SPA posts CSRF and answers without navigation and returns HTML for the next screen', async () => {
    const context = { document: { querySelector: () => null, querySelectorAll: () => [] }, FormData: class { constructor(form) { this.form = form; } } };
    runInNewContext(source, context);
    const form = { action: '/anamnesa/token/identifikasi', fields: { _token: 'csrf', nip: 'EMP-001' } };
    let requestOptions;
    const result = await context.postAnamnesa(form, async (url, options) => {
        assert.equal(url, form.action); requestOptions = options;
        return { ok: true, text: async () => '<main data-anamnesa-app>Form berikutnya</main>' };
    });
    assert.equal(requestOptions.credentials, 'same-origin');
    assert.equal(requestOptions.headers.Accept, 'application/json');
    assert.equal(requestOptions.body.form.fields._token, 'csrf');
    assert.equal(result.html, '<main data-anamnesa-app>Form berikutnya</main>');
});

test('SPA validation and network errors remain retryable without deleting answers', async () => {
    const context = { document: { querySelector: () => null, querySelectorAll: () => [] }, FormData: class {} };
    runInNewContext(source, context);
    const form = { action: '/kirim', answer: 'Pusing' };
    const errors = await context.postAnamnesa(form, async () => ({ ok: false, status: 422, json: async () => ({ errors: { 'answers.hepatitis.answer': ['Wajib diisi'] } }) }));
    assert.deepEqual(errors.errors['answers.hepatitis.answer'], ['Wajib diisi']);
    await assert.rejects(() => context.postAnamnesa(form, async () => ({ ok: false, status: 419 })), /Salin jawaban/);
    await assert.rejects(() => context.postAnamnesa(form, async () => { throw new Error('offline'); }), /offline/);
    assert.equal(form.answer, 'Pusing');
});

function confirmationSetup(reduced = false) {
    const events = {};
    const animations = [];
    let completeExit;
    const button = () => ({ events: {}, focused: false, focus() { this.focused = true; }, addEventListener(name, fn) { this.events[name] = fn; } });
    const cancel = button(); const accept = button(); const trigger = button();
    const dialog = {
        open: false, attributes: {},
        querySelector: (selector) => selector.includes('cancel') ? cancel : accept,
        addEventListener(name, fn) { events[name] = fn; },
        setAttribute(name, value) { this.attributes[name] = value; }, removeAttribute(name) { delete this.attributes[name]; },
        showModal() { this.open = true; }, close() { this.open = false; },
        animate(frames, options) {
            animations.push({ frames, options });
            return { cancel() {}, finished: options.duration === 220 ? Promise.resolve() : new Promise((resolve) => { completeExit = resolve; }) };
        },
    };
    const context = { document: { querySelector: () => null, querySelectorAll: () => [] }, window: { matchMedia: () => ({ matches: reduced }) }, getComputedStyle: () => ({ opacity: '0.8', getPropertyValue: () => '' }) };
    runInNewContext(source, context);
    return { ask: context.initializeAnamnesaConfirmation(dialog), dialog, events, animations, cancel, accept, trigger, finish: () => completeExit() };
}

test('modern reset confirmation focuses Cancel and only confirms after fade out completes', async () => {
    const ui = confirmationSetup();
    let result;
    const pending = ui.ask(ui.trigger).then((value) => { result = value; });
    assert.equal(ui.dialog.open, true);
    assert.equal(ui.cancel.focused, true);
    assert.equal(ui.animations[0].options.duration, 220);
    assert.equal(ui.animations[0].frames[0].opacity, 0);
    ui.accept.events.click();
    assert.equal(result, undefined);
    assert.equal(ui.dialog.open, true);
    assert.equal(ui.animations[1].options.duration, 200);
    assert.equal(ui.animations[1].frames[0].opacity, '0.8');
    ui.finish(); await pending;
    assert.equal(result, true);
    assert.equal(ui.dialog.open, false);
    assert.equal(ui.trigger.focused, true);
});

test('cancel and Escape preserve answers and reduced motion uses a gentle fade', async () => {
    const ui = confirmationSetup(true);
    const pending = ui.ask(ui.trigger);
    assert.equal(await ui.ask(ui.trigger), false);
    let prevented = false;
    ui.events.cancel({ preventDefault() { prevented = true; } });
    ui.accept.events.click();
    ui.finish();
    assert.equal(await pending, false);
    assert.equal(prevented, true);
    assert.deepEqual(ui.animations.map((animation) => animation.options.duration), [125, 125]);
    assert.equal(ui.trigger.focused, true);
    assert.doesNotMatch(source, /window\.confirm/);
    const next = ui.ask(ui.trigger);
    ui.cancel.events.click(); ui.finish();
    assert.equal(await next, false);
});
