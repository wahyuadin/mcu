import assert from 'node:assert/strict';
import { test } from 'node:test';
import { setupMasterBulkDelete } from '../../resources/js/master-bulk-delete.js';

class Element {
    constructor() { this.events = {}; this.checked = false; this.disabled = false; }
    addEventListener(name, callback) { this.events[name] = callback; }
}

function harness(run, { size = 3, accept = true, modal = false } = {}) {
    const previousWindow = globalThis.window;
    const form = new Element();
    const all = new Element();
    const rows = Array.from({ length: size }, () => new Element());
    const count = new Element();
    const submit = new Element();
    const confirmButton = new Element();
    const confirmCount = new Element();
    const confirmModal = modal ? {
        open: false,
        querySelector: (selector) => selector === '[data-confirm-submit]' ? confirmButton : confirmCount,
        showModal() { this.open = true; },
        close() { this.open = false; },
    } : undefined;
    let submitted = false;
    form.requestSubmit = () => {
        let prevented = false;
        form.events.submit({ preventDefault() { prevented = true; } });
        submitted = !prevented;
    };
    const confirmations = [];
    const events = {};
    const scope = {
        querySelector: (selector) => ({ '[data-bulk-delete-form]': form, '[data-bulk-delete-all]': all, '[data-bulk-delete-count]': count, '[data-bulk-delete-submit]': submit, '[data-bulk-delete-confirm]': confirmModal })[selector],
        querySelectorAll: () => rows,
    };
    globalThis.window = {
        confirm: (message) => { confirmations.push(message); return accept; },
        addEventListener: (event, callback) => { events[event] = callback; },
    };
    setupMasterBulkDelete(scope);
    try { run({ form, all, rows, count, submit, confirmations, events, confirmModal, confirmButton, confirmCount, wasSubmitted: () => submitted }); }
    finally {
        if (previousWindow === undefined) delete globalThis.window;
        else globalThis.window = previousWindow;
    }
}

test('select all selects only rows within the current table and toggles them off', () => {
    harness(({ all, rows, count, submit }) => {
        assert.equal(submit.disabled, true);
        assert.equal(count.textContent, '0 data terpilih');
        all.checked = true;
        all.events.change();
        assert.equal(rows.every((row) => row.checked), true);
        assert.equal(count.textContent, '3 data terpilih');
        assert.equal(submit.disabled, false);
        assert.equal(all.indeterminate, false);
        all.checked = false;
        all.events.change();
        assert.equal(rows.some((row) => row.checked), false);
        assert.equal(submit.disabled, true);
    });
});

test('individual selection updates count and select-all indeterminate state', () => {
    harness(({ all, rows, count }) => {
        rows[0].checked = true;
        rows[0].events.change();
        assert.equal(all.indeterminate, true);
        assert.equal(all.checked, false);
        assert.equal(count.textContent, '1 data terpilih');
        rows.forEach((row) => { row.checked = true; });
        rows[1].events.change();
        assert.equal(all.indeterminate, false);
        assert.equal(all.checked, true);
        rows[2].checked = false;
        rows[2].events.change();
        assert.equal(all.indeterminate, true);
        assert.equal(count.textContent, '2 data terpilih');
    });
});

test('empty tables disable selection and cannot submit or prompt for deletion', () => {
    harness(({ all, submit, form, confirmations }) => {
        assert.equal(all.disabled, true);
        assert.equal(submit.disabled, true);
        let prevented = false;
        form.events.submit({ preventDefault() { prevented = true; } });
        assert.equal(prevented, true);
        assert.equal(confirmations.length, 0);
    }, { size: 0 });
});

test('canceling confirmation preserves selection without submitting', () => {
    harness(({ rows, form, submit, confirmations }) => {
        rows[0].checked = true;
        rows[0].events.change();
        let prevented = false;
        form.events.submit({ preventDefault() { prevented = true; } });
        assert.equal(prevented, true);
        assert.equal(rows[0].checked, true);
        assert.equal(submit.disabled, false);
        assert.match(confirmations[0], /Hapus 1 data terpilih/);
    }, { accept: false });
});

test('confirmed deletion keeps selected IDs enabled in the payload and blocks duplicate submissions', () => {
    harness(({ all, rows, form, submit, confirmations, events }) => {
        all.checked = true;
        all.events.change();
        form.events.submit({ preventDefault() { throw new Error('Confirmed submission must proceed'); } });
        assert.equal(submit.disabled, true);
        assert.equal(submit.textContent, 'Menghapus…');
        assert.equal(all.disabled, true);
        assert.equal(rows.every((row) => row.checked && !row.disabled), true);
        let prevented = false;
        form.events.submit({ preventDefault() { prevented = true; } });
        assert.equal(prevented, true);
        assert.equal(confirmations.length, 1);
        events.pageshow();
        assert.equal(rows.some((row) => row.checked), false);
        assert.equal(submit.disabled, true);
        assert.equal(all.disabled, false);
        assert.equal(submit.textContent, 'Hapus terpilih');
    });
});

test('modal confirmation waits for approval without using browser confirm', () => {
    harness(({ rows, form, confirmModal, confirmButton, confirmCount, confirmations, wasSubmitted }) => {
        rows[0].checked = true;
        rows[0].events.change();
        form.requestSubmit();
        assert.equal(confirmModal.open, true);
        assert.equal(confirmCount.textContent, 1);
        assert.equal(wasSubmitted(), false);
        assert.equal(confirmations.length, 0);
        confirmModal.close();
        assert.equal(wasSubmitted(), false);
        assert.equal(rows[0].checked, true);
        form.requestSubmit();
        confirmButton.events.click();
        assert.equal(wasSubmitted(), true);
        assert.equal(confirmModal.open, false);
        assert.equal(rows[0].disabled, false);
    }, { modal: true });
});
