import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import { test } from 'node:test';

const source = readFileSync(
  new URL('../../resources/js/visit-participants.js', import.meta.url),
  'utf8'
);

function fixture() {
  const events = {};
  const requests = [];
  const timers = new Map();
  const replacements = [];
  const input = { value: '', name: 'q', matches: (selector) => selector === '[name="q"]' };
  const form = {
    action: 'https://mcu.test/kunjungan-mcu/visit',
    values: { q: '', status: 'registrasi' },
  };
  const download = {};
  const notice = {};
  const workspace = {
    prepend() {},
    setAttribute() {},
    removeAttribute() {},
    addEventListener: (name, fn) => {
      events[name] = fn;
    },
    querySelector: (selector) => {
      if (selector === '[data-visit-search]') return form;
      if (selector === '[name="q"]') return input;
      if (selector === '[data-visit-export]') return download;
      return { replaceWith: (node) => replacements.push(node) };
    },
  };
  runInNewContext(source, {
    URL,
    AbortController,
    Error,
    document: {
      querySelector: () => workspace,
      createElement: () => ({ ...notice, setAttribute() {} }),
      activeElement: null,
    },
    window: { addEventListener() {} },
    location: { pathname: '/kunjungan-mcu/visit' },
    history: { replaceState() {} },
    FormData: class {
      constructor() {
        return Object.entries(form.values);
      }
    },
    DOMParser: class {
      parseFromString(html) {
        return { querySelector: () => html };
      }
    },
    fetch: (url, options) => new Promise((resolve) => requests.push({ url, options, resolve })),
    setTimeout: (fn) => {
      const key = {};
      timers.set(key, fn);
      return key;
    },
    clearTimeout: (key) => timers.delete(key),
  });
  const type = (value) => {
    input.value = value;
    form.values.q = value;
    events.input({ target: input });
  };
  const flush = async () => {
    const callbacks = [...timers.values()];
    timers.clear();
    callbacks.forEach((fn) => fn());
    await Promise.resolve();
  };
  return { events, requests, replacements, input, download, type, flush };
}

test('search debounces, resets page and updates the filtered export link immediately', async () => {
  const h = fixture();
  h.type('Adi');
  h.type('Aditya');
  assert.equal(h.requests.length, 0);
  assert.equal(new URL(h.download.href).searchParams.get('q'), 'Aditya');
  assert.equal(new URL(h.download.href).searchParams.get('download'), 'xlsx');
  await h.flush();
  assert.equal(h.requests.length, 1);
  assert.equal(h.requests[0].url.searchParams.get('status'), 'registrasi');
  assert.equal(h.requests[0].url.searchParams.has('page'), false);
});

test('a response from an older search cannot overwrite the latest search', async () => {
  const h = fixture();
  h.type('old');
  await h.flush();
  h.type('new');
  assert.equal(h.requests[0].options.signal.aborted, true);
  h.requests[0].resolve({ ok: true, text: async () => 'old' });
  await new Promise((resolve) => setImmediate(resolve));
  assert.equal(h.replacements.length, 0);
  await h.flush();
  h.requests[1].resolve({ ok: true, text: async () => 'new' });
  await new Promise((resolve) => setImmediate(resolve));
  assert.deepEqual(h.replacements, ['new', 'new', 'new']);
});
