import assert from 'node:assert/strict';
import { test } from 'node:test';
import { moveOrderItem, setupItemOrderModal } from '../../resources/js/item-order.js';

class Element {
    constructor() { this.dataset = {}; this.children = []; this.events = {}; this.disabled = false; }
    append(...children) { children.forEach((child) => { child.parent = this; this.children.push(child); }); }
    replaceChildren() { this.children = []; }
    setAttribute(name, value) { this[name] = value; }
    addEventListener(event, callback) { this.events[event] = callback; }
    focus() { this.focused = true; }
    closest(selector) {
        if (selector === '[data-order-move]' && this.dataset.orderMove) return this;
        if (selector === '[data-order-item]' && this.dataset.orderItem) return this;
        return this.parent?.closest(selector) ?? null;
    }
    querySelector(selector) { return selector === 'button:not(:disabled)' ? this.children.find((child) => !child.disabled) : null; }
    querySelectorAll(selector) { return selector === '[data-dragging]' ? this.children.filter((child) => 'dragging' in child.dataset) : []; }
    getBoundingClientRect() { return { top: 0, height: 100, bottom: 100, left: 0, right: 100 }; }
}

const tick = () => new Promise((resolve) => setImmediate(resolve));

async function harness(run, { reduced = false, categoryOrder = false } = {}) {
    const originals = { window: globalThis.window, fetch: globalThis.fetch, getComputedStyle: globalThis.getComputedStyle };
    const board = new Element();
    const category = new Element();
    category.value = '';
    category.hidden = categoryOrder;
    Object.defineProperty(category, 'selectedOptions', { get: () => [{ dataset: { url: category.value ? '/order/category-a' : undefined } }] });
    const status = new Element();
    const save = new Element();
    const close = new Element();
    const trigger = new Element();
    trigger.dataset.categorySelect = 'category';
    if (categoryOrder) trigger.dataset.orderCategory = 'all';
    const dialog = new Element();
    dialog.open = false;
    dialog.showModal = () => { dialog.open = true; };
    dialog.close = () => { dialog.open = false; };
    const animations = [];
    dialog.animate = (frames, options) => {
        const animation = { frames, options, cancel() {}, finished: Promise.resolve() };
        animations.push(animation);
        return animation;
    };
    dialog.querySelector = (selector) => ({
        '[data-item-order-category]': category, '[data-item-order-board]': board,
        '[data-item-order-status]': status, '[data-item-order-save]': save,
        '[data-item-order-token]': { value: 'csrf-token' },
    })[selector];
    dialog.querySelectorAll = () => [close];
    const root = {
        createElement: () => new Element(), querySelectorAll: () => [trigger],
        querySelector: () => ({ value: 'category-a' }),
    };
    const calls = [];
    let failure = false;
    const records = [{ id: 'a', label: 'Hepatitis', type: 'Radio', is_active: true }, { id: 'b', label: 'Asma', type: 'Radio', is_active: false }, { id: 'c', label: 'Keterangan', type: 'Teks', is_active: true }];
    globalThis.window = { matchMedia: () => ({ matches: reduced }), confirm: () => true };
    globalThis.getComputedStyle = () => ({ opacity: '1', getPropertyValue: () => 'cubic-bezier(0.23, 1, 0.32, 1)' });
    globalThis.fetch = async (url, options) => {
        calls.push({ url, options });
        const isSave = options.method === 'PUT';
        return {
            ok: !(failure && isSave), headers: { get: () => 'application/json' },
            json: async () => isSave
                ? (failure ? { errors: { version: ['Daftar berubah. Muat ulang.'] } } : { message: 'Urutan tersimpan', version: 'new-version' })
                : { items: records, category: 'Riwayat', version: 'initial-version' },
        };
    };
    setupItemOrderModal(dialog, root);
    const open = async () => { trigger.events.click(); await tick(); };
    const down = (index) => board.events.click({ target: board.children[index].children[3].children[1] });
    try {
        await run({ dialog, board, category, status, save, close, trigger, animations, calls, open, down, fail: () => { failure = true; } });
    } finally {
        Object.entries(originals).forEach(([key, value]) => {
            if (value === undefined) delete globalThis[key];
            else globalThis[key] = value;
        });
    }
}

test('moving a card preserves all items and does not mutate the original order', () => {
    const items = ['a', 'b', 'c'];
    assert.deepEqual(moveOrderItem(items, 0, 2), ['b', 'c', 'a']);
    assert.deepEqual(items, ['a', 'b', 'c']);
    assert.deepEqual(moveOrderItem(items, -1, 1), items);
    assert.deepEqual(moveOrderItem(items, 0, 5), items);
});

test('category ordering loads all cards without a category field and saves their complete order', async () => {
    await harness(async ({ category, status, save, calls, open, down }) => {
        await open();
        assert.equal(category.value, 'all');
        assert.match(status.textContent, /3 kategori/);
        down(0);
        await save.events.click();
        assert.deepEqual(JSON.parse(calls.at(-1).options.body).items, ['b', 'a', 'c']);
    }, { categoryOrder: true });
});

test('modal loads every card and arrow movement saves all ids once without navigating', async () => {
    await harness(async ({ dialog, board, save, calls, open, down, status }) => {
        await open();
        assert.equal(dialog.open, true);
        assert.equal(board.children.length, 3);
        assert.equal(save.disabled, true);
        down(0);
        assert.equal(board.children[0].dataset.orderItem, 'b');
        assert.equal(save.disabled, false);
        await save.events.click();
        const put = calls.find((call) => call.options.method === 'PUT');
        assert.deepEqual(JSON.parse(put.options.body), { version: 'initial-version', items: ['b', 'a', 'c'] });
        assert.equal(put.options.headers['X-CSRF-TOKEN'], 'csrf-token');
        assert.equal(status.textContent, 'Urutan tersimpan');
        assert.equal(save.disabled, true);
    });
});

test('closing waits for fade out, restores trigger focus, and discards unsaved order on reopening', async () => {
    await harness(async ({ dialog, board, close, trigger, animations, calls, open, down }) => {
        await open();
        assert.equal(animations[0].options.duration, 220);
        down(0);
        let finish;
        const previousAnimate = dialog.animate;
        dialog.animate = (...args) => {
            const animation = previousAnimate(...args);
            animation.finished = new Promise((resolve) => { finish = resolve; });
            return animation;
        };
        const closing = close.events.click();
        assert.equal(dialog.open, true);
        assert.equal(animations.at(-1).options.duration, 200);
        assert.equal(animations.at(-1).frames[1].opacity, 0);
        finish();
        await closing;
        assert.equal(dialog.open, false);
        assert.equal(trigger.focused, true);
        dialog.animate = previousAnimate;
        await open();
        assert.equal(board.children[0].dataset.orderItem, 'a');
        assert.equal(calls.some((call) => call.options.method === 'PUT'), false);
    });
});

test('server validation errors remain in the modal without clearing unsaved cards', async () => {
    await harness(async ({ dialog, board, save, status, open, down, fail }) => {
        await open();
        down(0);
        fail();
        await save.events.click();
        assert.equal(dialog.open, true);
        assert.equal(status.textContent, 'Daftar berubah. Muat ulang.');
        assert.equal(board.children[0].dataset.orderItem, 'b');
        assert.equal(save.disabled, false);
    });
});

test('Escape uses the same fade out and reduced motion keeps a gentle opacity-only fade', async () => {
    await harness(async ({ dialog, animations, open }) => {
        await open();
        assert.equal(animations[0].options.duration, 125);
        assert.deepEqual(animations[0].frames, [{ opacity: 0 }, { opacity: 1 }]);
        let prevented = false;
        dialog.events.cancel({ preventDefault: () => { prevented = true; } });
        await tick();
        assert.equal(prevented, true);
        assert.equal(dialog.open, false);
        assert.equal(animations.at(-1).options.duration, 125);
    }, { reduced: true });
});

test('dropping below another card moves it to the correct position', async () => {
    await harness(async ({ board, save, open }) => {
        await open();
        board.events.dragstart({ target: board.children[0], dataTransfer: { setData() {} } });
        board.events.drop({ target: board.children[2], clientY: 90, preventDefault() {} });
        assert.deepEqual(board.children.map((card) => card.dataset.orderItem), ['b', 'c', 'a']);
        assert.equal(save.disabled, false);
    });
});
