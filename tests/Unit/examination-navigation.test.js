import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import { test } from 'node:test';
import { initializeBmi } from '../../resources/js/examination-fields.js';

const source = readFileSync(
  new URL('../../resources/js/examination-navigation.js', import.meta.url),
  'utf8'
).replace('export function', 'function');
const tick = () => new Promise((resolve) => setImmediate(resolve));

test('dirty tracking never overwrites realtime BMI and category from dynamic measurements', () => {
  const f = fixture();
  const height = { value: '160', addEventListener(type, fn) { this.update = fn; } };
  const weight = { value: '60', addEventListener(type, fn) { this.update = fn; } };
  const output = { closest: () => form };
  const form = {
    owner: f.root,
    elements: { namedItem: () => null },
    querySelector: (selector) => selector === '[data-bmi]' ? output : selector.includes('tinggi_badan') ? height : selector.includes('berat_badan') ? weight : null,
  };
  initializeBmi({ querySelectorAll: () => [output] });
  f.document.dispatchEvent({ type: 'input', target: { closest: () => form } });
  assert.equal(output.textContent, '23.44 · Kelebihan Berat Badan (Overweight)');
  weight.value = '50'; weight.update();
  f.document.dispatchEvent({ type: 'input', target: { closest: () => form } });
  assert.equal(output.textContent, '19.53 · Normal');
  height.value = ''; height.update();
  f.document.dispatchEvent({ type: 'input', target: { closest: () => form } });
  assert.equal(output.textContent, 'Belum dapat dihitung');
});

function fixture() {
  const docEvents = new Map();
  const winEvents = new Map();
  const requests = [];
  const dispatched = [];
  const pushes = [];
  const goes = [];
  const assigned = [];
  const notice = {};
  let confirmResult = true;
  let confirmations = 0;
  let root;
  const makeRoot = (name) => ({
    name,
    before() {},
    setAttribute() {},
    removeAttribute() {},
    contains(node) {
      return node?.owner === this;
    },
    querySelector() {
      return null;
    },
    replaceWith(next) {
      root = next;
    },
    replaceChildren() {
      this.cleared = true;
    },
  });
  root = makeRoot('summary');
  const document = {
    title: 'Ringkasan',
    querySelector: (selector) => (selector === '[data-examination-page]' ? root : null),
    createElement: () => Object.assign(notice, { setAttribute() {} }),
    addEventListener: (name, handler) => docEvents.set(name, handler),
    dispatchEvent: (event) => {
      dispatched.push(event.type);
      docEvents.get(event.type)?.(event);
    },
  };
  const window = {
    location: {
      href: 'https://mcu.test/visit/participant/pemeriksaan?tab=ringkasan',
      assign: (url) => assigned.push(String(url)),
    },
    history: {
      state: {},
      replaceState() {},
      pushState: (state, _, url) => {
        pushes.push(state);
        window.location.href = String(url);
      },
      go: (delta) => goes.push(delta),
    },
    setTimeout: () => 1,
    clearTimeout() {},
    confirm: () => {
      confirmations++;
      return confirmResult;
    },
    fetch: (url, options) =>
      new Promise((resolve, reject) => requests.push({ url, options, resolve, reject })),
    addEventListener: (name, handler) => winEvents.set(name, handler),
  };
  runInNewContext(source, {
    document,
    window,
    URL,
    Event,
    Set,
    AbortController,
    DOMParser: class {
      parseFromString(page) {
        return page;
      }
    },
  });
  const click = (tab, options = {}) => {
    const link = {
      owner: root,
      href: `https://mcu.test/visit/participant/pemeriksaan?tab=${tab}`,
      target: '',
      hasAttribute: () => false,
    };
    let prevented = false;
    docEvents.get('click')({
      button: 0,
      target: { closest: () => link },
      preventDefault: () => {
        prevented = true;
      },
      ...options,
    });
    return prevented;
  };
  const respond = async (index, name, status = 200) => {
    const next = makeRoot(name);
    requests[index].resolve({
      ok: status === 200,
      status,
      url: String(requests[index].url),
      text: async () => ({
        title: name,
        querySelector: (selector) => (selector === '[data-examination-page]' ? next : null),
      }),
    });
    await tick();
  };
  const dirty = () => {
    const form = {
      owner: root,
      elements: { namedItem() {} },
      querySelector() {
        return null;
      },
    };
    docEvents.get('input')({ target: { closest: () => form } });
  };
  return {
    click,
    respond,
    dirty,
    requests,
    dispatched,
    pushes,
    goes,
    assigned,
    notice,
    document,
    window,
    winEvents,
    get root() {
      return root;
    },
    get confirmations() {
      return confirmations;
    },
    cancel: () => {
      confirmResult = false;
    },
  };
}

test('tab navigation replaces only the examination region and updates URL and lifecycle', async () => {
  const h = fixture();
  assert.equal(h.click('identitas'), true);
  assert.equal(h.requests[0].options.cache, 'no-store');
  await h.respond(0, 'Identitas');
  assert.equal(h.root.name, 'Identitas');
  assert.equal(h.document.title, 'Identitas');
  assert.match(h.window.location.href, /tab=identitas/);
  assert.deepEqual(h.dispatched, ['mcu:exam-unmount', 'mcu:exam-mounted']);
  assert.equal(h.assigned.length, 0);
});

test('older responses cannot replace the newest tab', async () => {
  const h = fixture();
  h.click('identitas');
  h.click('fisik');
  assert.equal(h.requests[0].options.signal.aborted, true);
  await h.respond(1, 'Fisik');
  await h.respond(0, 'Identitas');
  assert.equal(h.root.name, 'Fisik');
  assert.equal(h.pushes.length, 1);
});

test('cancelling unsaved changes keeps the form and does not fetch', () => {
  const h = fixture();
  h.dirty();
  h.cancel();
  h.click('identitas');
  assert.equal(h.confirmations, 1);
  assert.equal(h.requests.length, 0);
  assert.equal(h.root.name, 'summary');
});

test('new edits during a pending request cannot be discarded by its response', async () => {
  const h = fixture();
  h.click('identitas');
  h.dirty();
  await h.respond(0, 'Identitas');
  assert.equal(h.root.name, 'summary');
  assert.equal(h.pushes.length, 0);
});

test('network failure keeps draft inputs and remains retryable', async () => {
  const h = fixture();
  h.dirty();
  h.click('identitas');
  h.requests[0].reject(new Error('offline'));
  await tick();
  assert.equal(h.root.name, 'summary');
  assert.match(h.notice.textContent, /gagal dimuat/);
  h.click('fisik');
  assert.equal(h.confirmations, 2);
  assert.equal(h.requests.length, 2);
});

test('Back uses the server-rendered tab without adding another history entry', async () => {
  const h = fixture();
  h.click('identitas');
  await h.respond(0, 'Identitas');
  h.window.location.href = 'https://mcu.test/visit/participant/pemeriksaan?tab=ringkasan';
  h.winEvents.get('popstate')({ state: { examinationIndex: 0 } });
  await h.respond(1, 'Ringkasan');
  assert.equal(h.root.name, 'Ringkasan');
  assert.equal(h.pushes.length, 1);
});

test('cancelling Back restores the current URL history position', async () => {
  const h = fixture();
  h.click('identitas');
  await h.respond(0, 'Identitas');
  h.dirty();
  h.cancel();
  h.winEvents.get('popstate')({ state: { examinationIndex: 0 } });
  assert.deepEqual(h.goes, [1]);
  assert.equal(h.requests.length, 1);
});

test('revoked access clears private content before falling back to normal navigation', async () => {
  const h = fixture();
  const old = h.root;
  h.click('identitas');
  await h.respond(0, 'Denied', 403);
  assert.equal(old.cleared, true);
  assert.equal(h.assigned.length, 1);
});

test('modified clicks retain normal browser behavior', () => {
  const h = fixture();
  assert.equal(h.click('identitas', { ctrlKey: true }), false);
  assert.equal(h.requests.length, 0);
});

test('clicking the current tab does not reload the document or fetch it again', () => {
  const h = fixture();
  assert.equal(h.click('ringkasan'), true);
  assert.equal(h.requests.length, 0);
  assert.equal(h.assigned.length, 0);
});
