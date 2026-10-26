import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';

const source = readFileSync(
  new URL('../../resources/js/checkpoint-print.js', import.meta.url),
  'utf8'
);

function setup({ hasCard = true } = {}) {
  let click;
  let printCalls = 0;
  const frames = [];
  const status = { textContent: '' };
  const button = {
    textContent: 'Cetak PDF',
    disabled: false,
    dataset: { printUrl: '/kunjungan-mcu/visit/peserta/patient/checkpoint' },
    closest: () => ({ querySelector: () => status }),
    addEventListener: (type, listener) => {
      if (type === 'click') click = listener;
    },
  };
  const document = {
    addEventListener() {},
    querySelectorAll: () => [button],
    body: { append: (frame) => frames.push(frame) },
    createElement: (tag) => {
      assert.equal(tag, 'iframe');
      const events = new Map();
      const printEvents = new Map();
      return {
        style: {},
        setAttribute() {},
        removed: false,
        remove() {
          this.removed = true;
        },
        addEventListener: (type, listener) => events.set(type, listener),
        load: () => events.get('load')(),
        afterprint: () => printEvents.get('afterprint')(),
        contentDocument: { querySelector: () => (hasCard ? {} : null) },
        contentWindow: {
          focus() {},
          print: () => {
            printCalls += 1;
          },
          addEventListener: (type, listener) => printEvents.set(type, listener),
        },
      };
    },
  };
  runInNewContext(source, { document });
  return { click: () => click(), button, frames, status, printCalls: () => printCalls };
}

test('print waits for the embedded document without opening or navigating a page', () => {
  const app = setup();
  app.click();
  assert.equal(app.frames.length, 1);
  assert.equal(app.frames[0].src, app.button.dataset.printUrl);
  assert.equal(app.button.disabled, true);
  assert.equal(app.printCalls(), 0);
  app.frames[0].load();
  assert.equal(app.printCalls(), 1);
  assert.equal(app.button.disabled, false);
  app.frames[0].afterprint();
  assert.equal(app.frames[0].removed, true);
});

test('a second click while loading does not duplicate print dialogs', () => {
  const app = setup();
  app.click();
  app.click();
  assert.equal(app.frames.length, 1);
});

test('login redirects or failed card responses show an error instead of printing', () => {
  const app = setup({ hasCard: false });
  app.click();
  app.frames[0].load();
  assert.equal(app.printCalls(), 0);
  assert.equal(app.frames[0].removed, true);
  assert.equal(app.button.disabled, false);
  assert.match(app.status.textContent, /Cetakan tidak dapat dimuat/);
});

test('printing again replaces any remaining embedded document', () => {
  const app = setup();
  app.click();
  app.frames[0].load();
  app.click();
  assert.equal(app.frames[0].removed, true);
  assert.equal(app.frames.length, 2);
  app.frames[1].load();
  assert.equal(app.printCalls(), 2);
});
