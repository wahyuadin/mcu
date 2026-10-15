import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
  startHrdPolling,
  moveHrdWidget,
  hrdRowValue,
  hrdWidgetValue,
  setupHrdDashboard,
  createHrdWidget,
  filterHrdCatalog,
} from '../../resources/js/hrd-dashboard.js';

class Timers {
  next = 0;
  tasks = new Map();
  setTimeout = (callback, delay) => {
    const id = ++this.next;
    this.tasks.set(id, { callback, delay });
    return id;
  };
  clearTimeout = (id) => this.tasks.delete(id);
  async run(delay) {
    const entry = [...this.tasks].find(([, task]) => task.delay === delay);
    assert.ok(entry, `Timer ${delay}ms must exist`);
    this.tasks.delete(entry[0]);
    await entry[1].callback();
    await flush();
  }
}
const flush = () => new Promise((resolve) => setImmediate(resolve));
const deferred = () => {
  let resolve, reject;
  const promise = new Promise((a, b) => {
    resolve = a;
    reject = b;
  });
  return { promise, resolve, reject };
};

class Element {
  children = [];
  dataset = {};
  style = {};
  events = {};
  attributes = {};
  className = '';
  value = '';
  checked = false;
  disabled = false;
  hidden = false;
  scrollTop = 0;
  constructor(tag = 'div', doc) {
    this.tagName = tag.toUpperCase();
    this.ownerDocument = doc;
    this.classList = {
      toggle: (name, enabled) => {
        const classes = new Set(this.className.split(' '));
        enabled ? classes.add(name) : classes.delete(name);
        this.className = [...classes].join(' ');
      },
    };
  }
  get textContent() {
    return (this.text ?? '') + this.children.map((node) => node.textContent).join('');
  }
  set textContent(value) {
    this.replaceChildren();
    this.text = String(value);
  }
  get options() {
    return this.children;
  }
  get selectedOptions() {
    return this.options.filter((node) => node.value === this.value);
  }
  append(...nodes) {
    nodes.forEach((node) => {
      node.remove();
      node.parent = this;
      this.children.push(node);
    });
  }
  insertBefore(node, reference) {
    node.remove();
    const index = reference ? this.children.indexOf(reference) : this.children.length;
    node.parent = this;
    this.children.splice(index, 0, node);
  }
  remove() {
    if (this.parent) {
      this.parent.children.splice(this.parent.children.indexOf(this), 1);
      this.parent = null;
    }
  }
  replaceChildren(...nodes) {
    this.text = '';
    this.children.forEach((node) => {
      node.parent = null;
    });
    this.children = [];
    this.append(...nodes);
  }
  replaceWith(node) {
    const parent = this.parent;
    parent.insertBefore(node, this);
    this.remove();
  }
  contains(node) {
    return this === node || this.children.some((child) => child.contains(node));
  }
  setAttribute(name, value) {
    this.attributes[name] = String(value);
  }
  addEventListener(name, callback) {
    (this.events[name] ??= []).push(callback);
  }
  removeEventListener(name, callback) {
    this.events[name] = (this.events[name] ?? []).filter((fn) => fn !== callback);
  }
  async emit(name, event = {}) {
    await Promise.all(
      (this.events[name] ?? []).map((callback) =>
        callback({ target: this, preventDefault() {}, ...event })
      )
    );
    await flush();
  }
  matches(selector) {
    if (selector.startsWith('[data-')) {
      const key = selector.slice(6, -1).replace(/-([a-z])/g, (_, letter) => letter.toUpperCase());
      return Object.hasOwn(this.dataset, key);
    }
    return selector.toUpperCase() === this.tagName;
  }
  querySelectorAll(selector) {
    const match = (node) => selector.split(',').some((part) => node.matches(part.trim()));
    return this.children.flatMap((node) => [
      ...(match(node) ? [node] : []),
      ...node.querySelectorAll(selector),
    ]);
  }
  querySelector(selector) {
    return this.querySelectorAll(selector)[0] ?? null;
  }
  closest(selector) {
    return this.matches(selector) ? this : (this.parent?.closest(selector) ?? null);
  }
  showModal() {
    this.open = true;
  }
  close() {
    if (this.open) {
      this.open = false;
      (this.events.close ?? []).forEach((callback) => callback());
    }
  }
  focus() {
    this.ownerDocument.activeElement = this;
  }
  reportValidity() {
    return true;
  }
}

function pollingHarness(load) {
  const doc = new Element();
  doc.hidden = false;
  const timers = new Timers();
  const applied = [];
  const errors = [];
  const poller = startHrdPolling({
    load,
    apply: (value) => applied.push(value),
    error: (error) => errors.push(error.message),
    document: doc,
    timers,
  });
  return { doc, timers, applied, errors, poller };
}

test('polling refreshes after four seconds without overlapping an unresolved request', async () => {
  const first = deferred();
  let count = 0;
  const h = pollingHarness(() => {
    count++;
    return count === 1 ? first.promise : Promise.resolve(count);
  });
  assert.equal(count, 1);
  h.poller.refresh();
  h.poller.refresh();
  assert.equal(count, 1);
  first.resolve('stale');
  await flush();
  assert.deepEqual(h.applied, []);
  await h.timers.run(0);
  assert.equal(count, 2);
  await h.timers.run(4000);
  assert.equal(count, 3);
  h.poller.stop();
  assert.equal(h.timers.tasks.size, 0);
});

test('hidden tabs pause updates and stale hidden responses cannot replace visible data', async () => {
  const first = deferred();
  let count = 0;
  const h = pollingHarness(() => (++count === 1 ? first.promise : Promise.resolve('fresh')));
  h.doc.hidden = true;
  await h.doc.emit('visibilitychange');
  first.resolve('hidden');
  await flush();
  assert.deepEqual(h.applied, []);
  assert.equal(h.timers.tasks.size, 0);
  h.doc.hidden = false;
  await h.doc.emit('visibilitychange');
  assert.deepEqual(h.applied, ['fresh']);
  h.poller.stop();
});

test('network failures keep the last result and schedule retry', async () => {
  let count = 0;
  const h = pollingHarness(async () => {
    if (++count === 2) throw new Error('offline');
    return count;
  });
  await flush();
  await h.timers.run(4000);
  assert.deepEqual(h.applied, [1]);
  assert.deepEqual(h.errors, ['offline']);
  await h.timers.run(4000);
  assert.deepEqual(h.applied, [1, 3]);
  h.poller.stop();
});

test('timeouts abort hung fetches and remain retryable', async () => {
  const h = pollingHarness(
    (signal) =>
      new Promise((_, reject) =>
        signal.addEventListener('abort', () =>
          reject(Object.assign(new Error('aborted'), { name: 'AbortError' }))
        )
      )
  );
  await h.timers.run(30000);
  assert.equal(h.errors.length, 1);
  assert.match(h.errors[0], /terlalu lama/);
  assert.equal([...h.timers.tasks.values()][0].delay, 4000);
  h.poller.stop();
});

test('builder pause does not resume when tab visibility changes and explicit resume fetches anew', async () => {
  let count = 0;
  const h = pollingHarness(async () => ++count);
  await flush();
  h.poller.pause();
  h.doc.hidden = true;
  await h.doc.emit('visibilitychange');
  h.doc.hidden = false;
  await h.doc.emit('visibilitychange');
  assert.equal(count, 1);
  h.poller.resume();
  await flush();
  assert.equal(count, 2);
  h.poller.stop();
});

test('widget moves preserve configuration and ignore out-of-bounds positions', () => {
  const widgets = [
    { source: 'a', width: 2 },
    { source: 'b', width: 1 },
  ];
  assert.deepEqual(moveHrdWidget(widgets, 0, 1), [widgets[1], widgets[0]]);
  assert.deepEqual(moveHrdWidget(widgets, 0, -1), widgets);
  assert.equal(widgets[0].source, 'a');
});

test('numeric, count and percentage measures display their actual selected values including zero and negative numbers', () => {
  assert.equal(hrdRowValue({ metric: 'average' }, { value: -3, count: 20 }), -3);
  assert.equal(hrdRowValue({ metric: 'percent' }, { value: null, percent: null }), null);
  assert.equal(hrdWidgetValue({ metric: 'min', numeric: { min: 0 } }), 0);
  assert.equal(hrdWidgetValue({ metric: 'percent', kind: 'laboratory', abnormal_percent: 50 }), 50);
  assert.equal(hrdWidgetValue({ metric: 'count', total: 14 }), 14);
});

function dashboardHarness(t, { builder = false } = {}) {
  const timers = new Timers();
  const doc = new Element();
  doc.activeElement = null;
  doc.hidden = false;
  doc.createElement = (tag) => new Element(tag, doc);
  doc.body = doc.createElement('body');
  const win = new Element();
  win.setTimeout = timers.setTimeout;
  win.clearTimeout = timers.clearTimeout;
  const root = doc.createElement('div');
  root.dataset = {
    company: 'company-a',
    dataUrl: '/hrd/data',
    layoutUrl: '/hrd/layout',
    previewUrl: '/hrd/preview',
    participantUrl: '/hrd/participants/00000000-0000-0000-0000-000000000000',
    exportUrl: '/hrd/exports',
    recapUrl: '/hrd/recap',
  };
  const nodes = {};
  const add = (key, tag = 'div', parent = root) => {
    const node = doc.createElement(tag);
    node.dataset[key] = '';
    nodes[key] = node;
    parent.append(node);
    return node;
  };
  const form = add('hrdFilters', 'form');
  const fields = {};
  for (const name of ['_token', 'visit', 'package', 'period', 'q']) {
    const field = doc.createElement(name === 'q' || name === '_token' ? 'input' : 'select');
    field.value = name === '_token' ? 'csrf' : '';
    fields[name] = field;
    form.append(field);
  }
  form.elements = { namedItem: (name) => fields[name] };
  form.reset = () =>
    Object.values(fields).forEach((field) => {
      if (field !== fields._token) field.value = '';
    });
  [
    'hrdWidgets',
    'hrdParticipants',
    'hrdError',
    'hrdLive',
    'hrdUpdated',
    'hrdSelected',
    'hrdParticipantCount',
    'hrdPage',
    'hrdExportState',
    'hrdExportStatus',
  ].forEach((key) => add(key));
  ['hrdReset', 'hrdCrossClear', 'hrdPrev', 'hrdNext', 'hrdExport', 'hrdRecap'].forEach((key) =>
    add(key, 'button')
  );
  add('hrdAll', 'input');
  add('hrdDownload', 'a');
  add('hrdExportProgress', 'progress');
  const confirm = add('hrdConfirm', 'dialog');
  add('hrdConfirmDescription', 'p', confirm);
  add('hrdConfirmCancel', 'button', confirm);
  add('hrdConfirmSubmit', 'button', confirm);
  const detail = add('hrdDetail', 'dialog');
  detail.append(doc.createElement('h2'));
  add('hrdDetailContent', 'div', detail);
  add('hrdDetailClose', 'button', detail);
  const statKeys = [
    'planned',
    'registered',
    'final',
    'remaining',
    'released',
    'canceled',
    'absent',
  ];
  statKeys.forEach((key) => {
    const stat = doc.createElement('strong');
    stat.dataset.hrdStat = key;
    root.append(stat);
  });
  if (builder) {
    add('hrdBuilderOpen', 'button');
    const toolbar = add('hrdBuilder', 'section');
    toolbar.hidden = true;
    add('hrdBuilderStatus', 'p', toolbar);
    ['hrdBuilderClose', 'hrdBuilderDefault', 'hrdBuilderSave'].forEach((key) =>
      add(key, 'button', toolbar)
    );
    add('hrdCatalogOpen', 'button', toolbar);
    const catalog = add('hrdCatalog', 'dialog');
    add('hrdCatalogList', 'div', catalog);
    add('hrdCatalogSearch', 'input', catalog);
    add('hrdCatalogCategory', 'select', catalog);
    add('hrdCatalogCount', 'p', catalog);
    add('hrdCatalogClose', 'button', catalog);
    add('hrdCatalogDone', 'button', catalog);
    const settings = add('hrdWidgetSettings', 'dialog');
    add('hrdSettingsClose', 'button', settings);
    const confirmation = add('hrdBuilderConfirm', 'dialog');
    confirmation.append(doc.createElement('h2'));
    add('hrdBuilderConfirmText', 'p', confirmation);
    add('hrdBuilderConfirmAccept', 'button', confirmation);
    add('hrdBuilderConfirmCancel', 'button', confirmation);
    const form = add('hrdWidgetForm', 'form', settings);
    const fields = {};
    [
      'source',
      'dimension',
      'title',
      'chart',
      'metric',
      'width',
      'height',
      'filter_source',
      'filter_value',
    ].forEach((name) => {
      fields[name] = doc.createElement(
        ['title', 'filter_value'].includes(name) ? 'input' : 'select'
      );
      form.append(fields[name]);
    });
    for (const [name, values] of [
      ['chart', ['bar', 'donut', 'table', 'indicator', 'number']],
      ['metric', ['count', 'percent', 'average', 'min', 'max']],
      ['width', [1, 2, 3]],
      ['height', [1, 2]],
    ]) {
      values.forEach((value) => {
        const option = doc.createElement('option');
        option.value = String(value);
        fields[name].append(option);
      });
      fields[name].value = String(values[0]);
    }
    form.elements = { namedItem: (name) => fields[name] };
    nodes.widgetFields = fields;
  }
  let result = {
    updated_at: '2026-10-15T10:00:00Z',
    page: 1,
    pages: 1,
    filtered_count: 1,
    export: null,
    options: [],
    sources: [
      { id: 'gender', title: 'Jenis kelamin', kind: 'choice', group: 'Demografi', medical: false },
    ],
    stats: {
      planned: 529,
      registered: 1,
      final: 1,
      remaining: 528,
      released: 1,
      canceled: 0,
      absent: 0,
    },
    widgets: [
      {
        source: 'gender',
        title: 'Jenis kelamin',
        kind: 'choice',
        group: 'Demografi',
        chart: 'bar',
        metric: 'count',
        width: 1,
        height: 1,
        total: 1,
        rows: [{ label: '<script>Pria</script>', count: 1, value: 1, percent: 100 }],
      },
    ],
    participants: [
      {
        id: 'participant-a',
        name: '<img src=x onerror=bad()>',
        number: 'MCU-001',
        package: 'Paket',
        department: 'Produksi',
        status: 'selesai',
        released: true,
      },
    ],
  };
  result.layout = result.widgets.map(({ source, title, chart, metric, width, height }) => ({
    source,
    title,
    chart,
    metric,
    width,
    height,
  }));
  let failure;
  const calls = [];
  const fetcher = async (url, options) => {
    calls.push({ url, options });
    if (failure) throw failure;
    return {
      ok: true,
      status: 200,
      headers: { get: () => 'application/json' },
      json: async () => structuredClone(result),
    };
  };
  t.mock.method(globalThis, 'fetch', fetcher);
  const original = globalThis.window;
  globalThis.window = win;
  t.after(() => {
    globalThis.window = original;
  });
  const dashboard = setupHrdDashboard(root);
  t.after(() => dashboard.poller.stop());
  return {
    nodes,
    root,
    fields,
    timers,
    calls,
    win,
    doc,
    dashboard,
    get result() {
      return result;
    },
    set result(value) {
      result = value;
    },
    fail(error) {
      failure = error;
    },
  };
}

test('unchanged polls preserve DOM, checkbox selection and focused controls and safely render untrusted labels', async (t) => {
  const h = dashboardHarness(t);
  await flush();
  const card = h.nodes.hrdWidgets.children[0];
  const row = h.nodes.hrdParticipants.children[0];
  const checkbox = row.querySelector('input');
  checkbox.checked = true;
  await checkbox.emit('change');
  checkbox.focus();
  await h.timers.run(4000);
  assert.equal(h.nodes.hrdWidgets.children[0], card);
  assert.equal(h.nodes.hrdParticipants.children[0], row);
  assert.equal(checkbox.checked, true);
  assert.equal(h.doc.activeElement, checkbox);
  assert.match(card.textContent, /<script>Pria<\/script>/);
  assert.equal(card.querySelector('script'), null);
  assert.equal(row.querySelector('img'), null);
  assert.match(h.nodes.hrdSelected.textContent, /1 peserta/);
});

test('draft filters are not applied during polling and submit resets selection without reloading', async (t) => {
  const h = dashboardHarness(t);
  await flush();
  h.fields.q.value = 'Draft name';
  await h.timers.run(4000);
  assert.ok(!h.calls.at(-1).url.includes('Draft'));
  await h.nodes.hrdFilters.emit('submit');
  assert.match(h.calls.at(-1).url, /q=Draft\+name/);
  assert.match(h.nodes.hrdSelected.textContent, /0 peserta/);
});

test('changed results update only changed cards while participant focus and layout remain', async (t) => {
  const h = dashboardHarness(t);
  await flush();
  const row = h.nodes.hrdParticipants.children[0];
  const checkbox = row.querySelector('input');
  checkbox.focus();
  const card = h.nodes.hrdWidgets.children[0];
  h.result.widgets[0].rows[0].count = 2;
  h.result.widgets[0].rows[0].value = 2;
  await h.timers.run(4000);
  assert.equal(h.nodes.hrdWidgets.children[0], card);
  assert.equal(h.nodes.hrdParticipants.children[0], row);
  assert.equal(h.doc.activeElement, checkbox);
  assert.equal(card.dataset.width, 1);
});

test('failed refresh keeps charts and draft inputs while showing an inline error', async (t) => {
  const h = dashboardHarness(t);
  await flush();
  const card = h.nodes.hrdWidgets.children[0];
  h.fields.q.value = 'keep draft';
  h.fail(new Error('offline'));
  await h.timers.run(4000);
  assert.equal(h.nodes.hrdWidgets.children[0], card);
  assert.equal(h.fields.q.value, 'keep draft');
  assert.match(h.nodes.hrdError.textContent, /Data terakhir tetap ditampilkan/);
});

test('inline builder opens with saved configuration, pauses polling and confirmed cancellation keeps the unsaved layout out of storage', async (t) => {
  const h = dashboardHarness(t, { builder: true });
  await flush();
  await h.nodes.hrdBuilderOpen.emit('click');
  assert.equal(h.nodes.hrdBuilder.hidden, false);
  assert.equal(h.root.dataset.editing, 'true');
  assert.equal(h.nodes.hrdWidgets.children.length, 2);
  assert.ok(![...h.timers.tasks.values()].some((task) => task.delay === 4000));
  await h.nodes.hrdBuilderDefault.emit('click');
  assert.equal(h.nodes.hrdBuilderConfirm.open, true);
  await h.nodes.hrdBuilderConfirmCancel.emit('click');
  const remove = h.nodes.hrdWidgets.children[0]
    .querySelectorAll('button')
    .find((button) => button.textContent === 'Hapus');
  await remove.emit('click');
  assert.equal(h.nodes.hrdWidgets.children.length, 1);
  await h.nodes.hrdBuilderClose.emit('click');
  assert.equal(h.nodes.hrdBuilderConfirm.open, true);
  await h.nodes.hrdBuilderConfirmAccept.emit('click');
  assert.equal(h.nodes.hrdBuilder.hidden, true);
  assert.ok(h.calls.every((call) => call.options.method !== 'PUT'));
  assert.equal(h.nodes.hrdWidgets.children.length, 1);
});

test('catalog defaults are compatible with KPI, numerical, text and laboratory sources', () => {
  assert.equal(createHrdWidget({ id: 'stat:final', title: 'Final', kind: 'kpi' }).chart, 'number');
  assert.equal(
    createHrdWidget({ id: 'height', title: 'Tinggi', kind: 'number' }).metric,
    'average'
  );
  assert.equal(createHrdWidget({ id: 'text', title: 'Catatan', kind: 'text' }).chart, 'table');
  assert.equal(createHrdWidget({ id: 'lab', title: 'Hb', kind: 'laboratory' }).chart, 'indicator');
  const sources = [
    { title: 'Hemoglobin', group: 'Laboratorium', unit: 'g/dL' },
    { title: 'Departemen', group: 'Demografi' },
  ];
  assert.deepEqual(filterHrdCatalog(sources, ' HEMO ', 'Laboratorium'), [sources[0]]);
  assert.deepEqual(filterHrdCatalog(sources, 'g/dl'), [sources[0]]);
  assert.deepEqual(filterHrdCatalog(sources, 'Hemoglobin', 'Demografi'), []);
});

test('catalog search, add and settings update the draft and save only to the personal endpoint', async (t) => {
  const h = dashboardHarness(t, { builder: true });
  await flush();
  await h.nodes.hrdBuilderOpen.emit('click');
  await h.nodes.hrdCatalogOpen.emit('click');
  assert.equal(h.nodes.hrdCatalog.open, true);
  h.nodes.hrdCatalogSearch.value = 'missing';
  await h.nodes.hrdCatalogSearch.emit('input');
  assert.match(h.nodes.hrdCatalogList.textContent, /Tidak ada widget/);
  h.nodes.hrdCatalogSearch.value = '';
  await h.nodes.hrdCatalogSearch.emit('input');
  await h.nodes.hrdCatalogList.querySelector('button').emit('click');
  assert.match(h.nodes.hrdCatalogList.textContent, /2 ditambahkan/);
  await h.nodes.hrdCatalogDone.emit('click');
  await h.nodes.hrdWidgets.children[1].querySelector('[data-hrd-settings-open]').emit('click');
  h.nodes.widgetFields.title.value = 'Judul pribadi';
  h.nodes.widgetFields.width.value = '2';
  await h.nodes.hrdWidgetForm.emit('submit');
  assert.equal(h.nodes.hrdWidgets.children[1].dataset.width, 2);
  await h.nodes.hrdBuilderSave.emit('click');
  const save = h.calls.find((call) => call.options.method === 'PUT');
  const body = JSON.parse(save.options.body);
  assert.equal(body.widgets.length, 2);
  assert.equal(body.widgets[1].title, 'Judul pribadi');
  assert.equal(body.widgets[1].width, 2);
  assert.equal(body.use_default, false);
  assert.equal(h.nodes.hrdBuilder.hidden, true);
});

test('KPI cards show zero instead of an empty-data message and unknown targets remain a dash', async (t) => {
  const h = dashboardHarness(t);
  h.result.personal_layout = true;
  h.result.widgets = [
    {
      source: 'stat:registered',
      title: 'Terdaftar',
      kind: 'kpi',
      total: 0,
      width: 1,
      height: 1,
      rows: [],
    },
    {
      source: 'stat:planned',
      title: 'Target',
      kind: 'kpi',
      total: null,
      width: 1,
      height: 1,
      rows: [],
    },
  ];
  await flush();
  await h.timers.run(4000);
  assert.match(h.nodes.hrdWidgets.children[0].textContent, /0/);
  assert.ok(!h.nodes.hrdWidgets.children[0].textContent.includes('Belum ada data'));
  assert.match(h.nodes.hrdWidgets.children[1].textContent, /—/);
});

test('failed saves retain the draft and show the actual error without resuming polling', async (t) => {
  const h = dashboardHarness(t, { builder: true });
  await flush();
  await h.nodes.hrdBuilderOpen.emit('click');
  await h.nodes.hrdWidgets.children[0].querySelector('[data-hrd-settings-open]').emit('click');
  h.nodes.widgetFields.title.value = 'Perubahan belum disimpan';
  await h.nodes.hrdWidgetForm.emit('submit');
  h.fail(new Error('Koneksi offline'));
  await h.nodes.hrdBuilderSave.emit('click');
  assert.equal(h.nodes.hrdBuilder.hidden, false);
  assert.match(h.nodes.hrdWidgets.children[0].textContent, /Perubahan belum disimpan/);
  assert.match(h.nodes.hrdBuilderStatus.textContent, /Koneksi offline/);
  assert.equal(h.nodes.hrdBuilderSave.disabled, false);
  assert.ok(![...h.timers.tasks.values()].some((task) => task.delay === 4000));
});

test('default reset is a confirmed preview until saved with an explicit reset flag', async (t) => {
  const h = dashboardHarness(t, { builder: true });
  await flush();
  await h.nodes.hrdBuilderOpen.emit('click');
  await h.nodes.hrdBuilderDefault.emit('click');
  assert.equal(h.nodes.hrdBuilderConfirm.open, true);
  assert.ok(h.calls.every((call) => call.options.method !== 'PUT'));
  await h.nodes.hrdBuilderConfirmAccept.emit('click');
  await h.timers.run(900);
  const preview = h.calls.find((call) => call.options.method === 'POST');
  assert.deepEqual(JSON.parse(preview.options.body), { widgets: [], use_default: true });
  await h.nodes.hrdBuilderSave.emit('click');
  const save = h.calls.find((call) => call.options.method === 'PUT');
  assert.deepEqual(JSON.parse(save.options.body), { widgets: [], use_default: true });
});

test('revoked access clears private display instead of retaining medical content as a network fallback', async (t) => {
  const h = dashboardHarness(t);
  await flush();
  h.nodes.hrdDetailContent.textContent = 'Private result';
  h.nodes.hrdDetail.showModal();
  h.fail(Object.assign(new Error('Access revoked'), { revoked: true }));
  await h.timers.run(4000);
  assert.equal(h.nodes.hrdWidgets.children.length, 0);
  assert.equal(h.nodes.hrdParticipants.children.length, 0);
  assert.equal(h.nodes.hrdDetailContent.textContent, '');
  assert.equal(h.nodes.hrdExport.disabled, true);
});

test('access revoked during an editor preview closes editing and clears private display despite paused polling', async (t) => {
  const h = dashboardHarness(t, { builder: true });
  await flush();
  await h.nodes.hrdBuilderOpen.emit('click');
  await h.nodes.hrdWidgets.children[0].querySelector('[data-hrd-settings-open]').emit('click');
  h.nodes.widgetFields.title.value = 'Private draft';
  await h.nodes.hrdWidgetForm.emit('submit');
  h.fail(Object.assign(new Error('Akses dicabut'), { revoked: true }));
  await h.timers.run(900);
  assert.equal(h.nodes.hrdBuilder.hidden, true);
  assert.equal(h.nodes.hrdWidgets.children.length, 0);
  assert.equal(h.nodes.hrdParticipants.children.length, 0);
  assert.equal(h.nodes.hrdBuilderOpen.disabled, true);
  assert.match(h.nodes.hrdError.textContent, /Akses dicabut/);
});
