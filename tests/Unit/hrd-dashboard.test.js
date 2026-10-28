import assert from 'node:assert/strict';
import { test } from 'node:test';
import { setupOperationalDashboard } from '../../resources/js/dashboard.js';
import {
  startHrdPolling,
  moveHrdWidget,
  hrdRowValue,
  hrdWidgetValue,
  setupHrdDashboard,
  createHrdWidget,
  filterHrdCatalog,
  dashboardRateLimitError,
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
      add: (...names) => {
        this.className = [...new Set([...this.className.split(' '), ...names])].join(' ');
      },
      remove: (...names) => {
        this.className = this.className
          .split(' ')
          .filter((name) => !names.includes(name))
          .join(' ');
      },
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
  removeAttribute(name) {
    delete this.attributes[name];
    delete this[name];
  }
  click() {
    (this.ownerDocument.downloads ??= []).push({ href: this.href, download: this.download });
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
        callback({
          type: name,
          target: this,
          preventDefault() {},
          stopImmediatePropagation() {},
          ...event,
        })
      )
    );
    await flush();
  }
  matches(selector) {
    if (selector.includes(','))
      return selector.split(',').some((part) => this.matches(part.trim()));
    if (selector.startsWith('.')) return this.className.split(' ').includes(selector.slice(1));
    if (selector.startsWith('[data-')) {
      const key = selector.slice(6, -1).replace(/-([a-z])/g, (_, letter) => letter.toUpperCase());
      return Object.hasOwn(this.dataset, key);
    }
    return selector.toUpperCase() === this.tagName;
  }
  get nextElementSibling() {
    return this.parent?.children[this.parent.children.indexOf(this) + 1] ?? null;
  }
  getBoundingClientRect() {
    return this.rect?.() ?? { left: 0, top: 0, width: 100, height: 100, right: 100, bottom: 100 };
  }
  cloneNode(deep = false) {
    const node = new Element(this.tagName, this.ownerDocument);
    node.dataset = { ...this.dataset };
    node.className = this.className;
    node.style = { ...this.style };
    node.text = this.text;
    if (deep) node.append(...this.children.map((child) => child.cloneNode(true)));
    return node;
  }
  setPointerCapture(id) {
    this.pointerId = id;
  }
  hasPointerCapture(id) {
    return this.pointerId === id;
  }
  releasePointerCapture() {
    this.pointerId = undefined;
  }
  animate(frames, options) {
    (this.animations ??= []).push({ frames, options });
    return { cancel() {}, finished: Promise.resolve() };
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

test('rate limit errors honor seconds, HTTP dates and a safe fallback without exposing debug traces', (t) => {
  t.mock.method(Date, 'now', () => Date.parse('2026-10-15T10:00:00Z'));
  const response = (value) => ({ headers: { get: () => value } });
  assert.equal(dashboardRateLimitError(response('12')).retryAfter, 12);
  assert.equal(dashboardRateLimitError(response('Thu, 15 Oct 2026 10:00:30 GMT')).retryAfter, 30);
  assert.equal(dashboardRateLimitError(response(null)).retryAfter, 60);
  assert.equal(dashboardRateLimitError(response('0')).retryAfter, 1);
});

test('polling waits for Retry-After even when a user refreshes or resumes during the cooldown', async () => {
  let calls = 0;
  const h = pollingHarness(async () => {
    calls++;
    if (calls === 1) throw dashboardRateLimitError({ headers: { get: () => '7' } });
    return { value: 'latest' };
  });
  await flush();
  h.poller.refresh();
  h.poller.pause();
  h.poller.resume();
  await flush();
  assert.equal(calls, 1);
  assert.match(h.errors[0], /7 detik/);
  await h.timers.run(7000);
  assert.equal(calls, 2);
  assert.deepEqual(h.applied, [{ value: 'latest' }]);
  h.poller.stop();
});

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

function dashboardHarness(t, { builder = false, unified = false } = {}) {
  const timers = new Timers();
  const doc = new Element();
  doc.activeElement = null;
  doc.hidden = false;
  doc.createElement = (tag) => new Element(tag, doc);
  doc.body = doc.createElement('body');
  const win = new Element();
  win.setTimeout = timers.setTimeout;
  win.clearTimeout = timers.clearTimeout;
  win.location = { href: 'http://localhost/dashboard' };
  win.history = {
    replaceState: (_state, _unused, url) => {
      win.location.href = String(url);
    },
  };
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
    [
      'hrdBuilderClose',
      'hrdBuilderDefault',
      'hrdBuilderSave',
      'dashboardUndo',
      'dashboardRedo',
    ].forEach((key) => add(key, 'button', toolbar));
    add('hrdCatalogOpen', 'button', toolbar);
    const catalog = add('hrdCatalog', 'dialog');
    add('hrdCatalogList', 'div', catalog);
    add('hrdCatalogSearch', 'input', catalog);
    add('hrdCatalogCategory', 'select', catalog);
    add('hrdCatalogCount', 'p', catalog);
    add('hrdCatalogClose', 'button', catalog);
    add('hrdCatalogDone', 'button', catalog);
    const settings = add('hrdWidgetSettings', unified ? 'aside' : 'dialog');
    settings.hidden = unified;
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
  if (unified) {
    root.dataset.dataUrl = '/dashboard/data';
    root.dataset.previewUrl = '/dashboard/preview';
    root.dataset.savedUrl = '/dashboard/saved';
    root.dataset.selectionUrl = '/dashboard/selection';
    nodes.hrdWidgets.dataset.dashboardWidgets = '';
    add('dashboardDefault');
    add('dashboardError');
    add('dashboardStatus');
    add('dashboardVisits', 'tbody');
    add('hrdLayoutNote');
    add('dashboardCrossClear', 'button');
    add('dashboardContext', 'select').value = 'company-a';
    add('dashboardVisit', 'select');
    add('dashboardVisitStatus', 'select');
    add('dashboardFilterReset', 'button');
    add('dashboardSelect', 'select');
    const actions = add('dashboardLibraryActions');
    ['dashboardNew', 'dashboardDuplicate', 'dashboardRename', 'dashboardDelete'].forEach((key) =>
      add(key, 'button', actions)
    );
    add('dashboardSaveAs', 'button', nodes.hrdBuilder);
    const nameDialog = add('dashboardNameDialog', 'dialog');
    nameDialog.append(doc.createElement('h2'));
    const nameForm = add('dashboardNameForm', 'form', nameDialog);
    const name = doc.createElement('input');
    nameForm.append(name);
    nameForm.elements = { namedItem: () => name };
    add('dashboardNameCancel', 'button', nameForm);
    nameForm.append(doc.createElement('button'));
    add('dashboardNameError', 'p', nameForm);
    result.active_dashboard = 'dashboard-one';
    result.dashboard = { id: 'dashboard-one', name: 'Dashboard pertama', version: 1 };
    result.dashboards = [result.dashboard];
    result.personal_layout = true;
    result.recent_visits = [];
    result.stats = { perusahaan: 1, kunjungan_aktif: 1, peserta: 1, selesai: 0 };
    result.default_dashboard = { id: 'default', name: 'Dashboard', version: 1 };
    result.visit_options = [
      { id: 'visit-a', company_id: 'company-a', status: 'draft', number: 'MCU-A', period: '2026' },
      {
        id: 'visit-b',
        company_id: 'company-a',
        status: 'ongoing',
        number: 'MCU-B',
        period: '2026',
      },
      { id: 'visit-c', company_id: 'company-b', status: 'closed', number: 'MCU-C', period: '2026' },
    ];
  }
  let failure;
  const calls = [];
  const fetcher = async (url, options) => {
    calls.push({ url, options });
    if (failure) throw failure;
    let payload = result;
    if (unified && url.startsWith('/dashboard/preview')) {
      const draft = JSON.parse(options.body).widgets;
      payload = {
        ...result,
        layout: draft,
        widgets: draft.map((config) => ({
          ...result.widgets.find((widget) => widget.source === config.source),
          ...config,
        })),
      };
    }
    if (
      unified &&
      ['PATCH', 'POST'].includes(options.method) &&
      url.startsWith('/dashboard/saved')
    ) {
      const body = JSON.parse(options.body);
      payload = {
        dashboard: {
          id: options.method === 'POST' ? 'new-dashboard' : 'dashboard-one',
          name: body.name ?? 'Dashboard pertama',
          widgets: body.widgets,
          version: 2,
        },
      };
    }
    return {
      ok: true,
      status: 200,
      headers: { get: () => 'application/json' },
      json: async () => structuredClone(payload),
    };
  };
  t.mock.method(globalThis, 'fetch', fetcher);
  const original = globalThis.window;
  globalThis.window = win;
  t.after(() => {
    globalThis.window = original;
  });
  const dashboard = unified ? setupOperationalDashboard(root) : setupHrdDashboard(root);
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

test('participant names link to the full examination and retain dashboard filters without opening the old dialog', async (t) => {
  const h = dashboardHarness(t);
  h.result.participants[0].examination_url = '/kunjungan-mcu/visit-a/peserta/person-a/pemeriksaan';
  h.win.location.href =
    'http://localhost/dashboard?dashboard=default&visit=visit-a&visit_status=closed';
  await flush();
  const link = h.nodes.hrdParticipants.querySelector('a');
  assert.equal(link.textContent, h.result.participants[0].name);
  const target = new URL(link.href);
  assert.equal(target.pathname, '/kunjungan-mcu/visit-a/peserta/person-a/pemeriksaan');
  assert.equal(target.searchParams.get('visit_status'), 'closed');
  assert.equal(target.searchParams.get('dashboard'), 'default');
  assert.equal(target.searchParams.get('from_dashboard'), '1');
  await link.emit('click');
  assert.ok(!h.nodes.hrdDetail.open);
});

test('CSV downloads only save a validated CSV response and never a JSON error page', async (t) => {
  const h = dashboardHarness(t);
  await flush();
  let receivedBlob;
  t.mock.method(URL, 'createObjectURL', (blob) => {
    receivedBlob = blob;
    return 'blob:excel-csv';
  });
  let response = {
    ok: true,
    status: 200,
    headers: { get: () => 'application/json' },
    json: async () => ({ message: 'Error' }),
  };
  t.mock.method(globalThis, 'fetch', async () => response);
  await h.nodes.hrdRecap.emit('click');
  assert.equal(receivedBlob, undefined);
  assert.equal(h.doc.downloads, undefined);
  assert.match(h.nodes.hrdError.textContent, /tidak mengirim file CSV/);
  response = {
    ok: true,
    status: 200,
    headers: { get: () => 'text/csv; charset=UTF-8' },
    blob: async () =>
      new Blob(['\uFEFFsep=;\r\nKategori;Hasil\r\nMCU;正常\r\n'], { type: 'text/csv' }),
  };
  await h.nodes.hrdRecap.emit('click');
  assert.match(await receivedBlob.text(), /正常/);
  assert.deepEqual(h.doc.downloads, [{ href: 'blob:excel-csv', download: 'Rekap_MCU.csv' }]);
  assert.equal(h.nodes.hrdRecap.disabled, false);
});

test('CSV rate limits keep selection intact, show a countdown and do not retry the download automatically', async (t) => {
  const h = dashboardHarness(t);
  await flush();
  const checkbox = h.nodes.hrdParticipants.querySelector('input');
  checkbox.checked = true;
  await checkbox.emit('change');
  let requests = 0;
  t.mock.method(globalThis, 'fetch', async () => {
    requests++;
    return {
      ok: false,
      status: 429,
      headers: { get: (name) => (name === 'Retry-After' ? '2' : 'application/json') },
    };
  });
  await h.nodes.hrdRecap.emit('click');
  assert.equal(h.nodes.hrdRecap.disabled, true);
  assert.match(h.nodes.hrdRecap.textContent, /2 detik/);
  assert.equal(checkbox.checked, true);
  await h.nodes.hrdRecap.emit('click');
  assert.equal(requests, 1);
  await h.timers.run(1000);
  await h.timers.run(1000);
  assert.equal(h.nodes.hrdRecap.disabled, false);
  assert.equal(requests, 1);
  assert.equal(h.doc.downloads, undefined);
});

test('CSV requests cannot overlap and an old response cannot download after company scope changes', async (t) => {
  const h = dashboardHarness(t);
  await flush();
  const pending = deferred();
  let downloads = 0;
  let requests = 0;
  t.mock.method(URL, 'createObjectURL', () => {
    downloads++;
    return 'blob:stale';
  });
  t.mock.method(globalThis, 'fetch', async (url) => {
    if (url.startsWith('/hrd/recap')) {
      requests++;
      await pending.promise;
      return {
        ok: true,
        status: 200,
        headers: { get: () => 'text/csv' },
        blob: async () => new Blob(['old company']),
      };
    }
    return {
      ok: true,
      status: 200,
      headers: { get: () => 'application/json' },
      json: async () => h.result,
    };
  });
  const downloading = h.nodes.hrdRecap.emit('click');
  await flush();
  await h.nodes.hrdRecap.emit('click');
  assert.equal(requests, 1);
  h.dashboard.setCompany('company-b');
  pending.resolve();
  await downloading;
  assert.equal(downloads, 0);
  assert.equal(h.doc.downloads, undefined);
});

test('ZIP rate limits never repeat the POST or discard selected participants', async (t) => {
  const h = dashboardHarness(t);
  await flush();
  const checkbox = h.nodes.hrdParticipants.querySelector('input');
  checkbox.checked = true;
  await checkbox.emit('change');
  let requests = 0;
  t.mock.method(globalThis, 'fetch', async () => {
    requests++;
    return {
      ok: false,
      status: 429,
      headers: { get: (name) => (name === 'Retry-After' ? '2' : 'application/json') },
    };
  });
  await h.nodes.hrdExport.emit('click');
  await h.nodes.hrdConfirmSubmit.emit('click');
  assert.equal(h.nodes.hrdExport.disabled, true);
  assert.match(h.nodes.hrdExport.textContent, /2 detik/);
  assert.equal(checkbox.checked, true);
  await h.nodes.hrdConfirmSubmit.emit('click');
  await h.timers.run(1000);
  await h.timers.run(1000);
  assert.equal(requests, 1);
  assert.equal(h.nodes.hrdExport.disabled, false);
  assert.match(h.nodes.hrdSelected.textContent, /1 peserta/);
});

function enableGestures(h, reduced = false) {
  const grid = h.nodes.hrdWidgets;
  const rect = (left, top, width, height) => ({
    left,
    top,
    width,
    height,
    right: left + width,
    bottom: top + height,
  });
  grid.rect = () => rect(0, 100, 900, 1000);
  h.win.innerHeight = 1000;
  h.win.getComputedStyle = () => ({
    gridTemplateColumns: '280px 280px 280px',
    columnGap: '20px',
    getPropertyValue: () => 'cubic-bezier(0.23, 1, 0.32, 1)',
  });
  h.win.matchMedia = (query) => ({ matches: query.includes('prefers-reduced-motion') && reduced });
  const boxes = () => {
    let col = 0,
      y = 100,
      rowHeight = 0;
    return [...grid.querySelectorAll('[data-hrd-edit-index]')].map((card) => {
      const span = Math.min(3, Number(card.dataset.width));
      if (col + span > 3) {
        y += rowHeight + 20;
        col = 0;
        rowHeight = 0;
      }
      const height = parseFloat(card.style.height) || 250 + 190 * (Number(card.dataset.height) - 1);
      const width = parseFloat(card.style.width) || span * (920 / 3) - 20;
      const box = rect(col * (920 / 3), y, width, height);
      col += span;
      rowHeight = Math.max(rowHeight, height);
      return { card, box };
    });
  };
  const installRects = () =>
    grid.querySelectorAll('[data-hrd-edit-index]').forEach((card) => {
      card.rect = () => boxes().find((entry) => entry.card === card).box;
    });
  h.doc.elementFromPoint = (x, y) =>
    boxes().find(({ box }) => x >= box.left && x <= box.right && y >= box.top && y <= box.bottom)
      ?.card;
  return {
    grid,
    installRects,
    pointer: (target, x, y, extra = {}) => ({
      target,
      clientX: x,
      clientY: y,
      button: 0,
      pointerId: 1,
      pointerType: 'mouse',
      isPrimary: true,
      ...extra,
    }),
  };
}

test('visit and status dropdowns apply immediately and reconcile incompatible visits without a submit', async (t) => {
  const h = dashboardHarness(t, { builder: true, unified: true });
  await flush();
  h.nodes.dashboardVisit.value = 'visit-a';
  await h.nodes.dashboardVisit.emit('change');
  assert.match(h.calls.at(-1).url, /visit=visit-a/);
  h.nodes.dashboardVisitStatus.value = 'ongoing';
  await h.nodes.dashboardVisitStatus.emit('change');
  assert.match(h.calls.at(-1).url, /visit_status=ongoing/);
  assert.ok(!h.calls.at(-1).url.includes('visit=visit-a'));
  assert.equal(h.nodes.dashboardVisit.value, '');
  assert.deepEqual(
    h.nodes.dashboardVisit.options.map((option) => option.value),
    ['', 'visit-b']
  );
  await h.nodes.dashboardFilterReset.emit('click');
  assert.ok(!h.calls.at(-1).url.includes('visit_status'));
  assert.equal(h.root.dataset.company, '');
});

test('manual filtering during edit updates previews but never discards the draft', async (t) => {
  const h = dashboardHarness(t, { builder: true, unified: true });
  await flush();
  await h.nodes.hrdBuilderOpen.emit('click');
  await h.nodes.hrdWidgets.children[0].querySelector('[data-hrd-settings-open]').emit('click');
  h.nodes.widgetFields.title.value = 'Tetap disimpan';
  await h.nodes.hrdWidgetForm.emit('input');
  h.nodes.dashboardVisitStatus.value = 'closed';
  await h.nodes.dashboardVisitStatus.emit('change');
  await h.timers.run(300);
  assert.match(h.calls.at(-1).url, /visit_status=closed/);
  assert.equal(JSON.parse(h.calls.at(-1).options.body).widgets[0].title, 'Tetap disimpan');
  assert.equal(h.nodes.hrdBuilder.hidden, false);
  h.dashboard.editor.close(false);
});

test('undo and redo restore live edits and adding a new edit clears the redo branch', async (t) => {
  const h = dashboardHarness(t, { builder: true, unified: true });
  await flush();
  await h.nodes.hrdBuilderOpen.emit('click');
  await h.nodes.hrdWidgets.children[0].querySelector('[data-hrd-settings-open]').emit('click');
  h.nodes.widgetFields.title.value = 'Judul baru';
  await h.nodes.hrdWidgetForm.emit('input');
  assert.equal(h.nodes.dashboardUndo.disabled, false);
  await h.nodes.dashboardUndo.emit('click');
  assert.match(h.nodes.hrdWidgets.children[0].textContent, /Jenis kelamin/);
  assert.equal(h.nodes.hrdWidgetSettings.hidden, false);
  assert.equal(h.nodes.widgetFields.title.value, 'Jenis kelamin');
  assert.equal(h.nodes.dashboardRedo.disabled, false);
  await h.nodes.dashboardRedo.emit('click');
  assert.match(h.nodes.hrdWidgets.children[0].textContent, /Judul baru/);
  await h.nodes.dashboardUndo.emit('click');
  await h.nodes.hrdWidgets.children[0].querySelector('[data-hrd-settings-open]').emit('click');
  h.nodes.widgetFields.title.value = 'Cabang baru';
  await h.nodes.hrdWidgetForm.emit('input');
  assert.equal(h.nodes.dashboardRedo.disabled, true);
  assert.ok(h.calls.every((call) => !call.url.startsWith('/dashboard/saved')));
  h.dashboard.editor.close(false);
});

test('dragging the card surface reorders live cards and animates siblings without drag or arrow buttons', async (t) => {
  const h = dashboardHarness(t, { builder: true, unified: true });
  h.result.layout = ['Satu', 'Dua', 'Tiga'].map((title) => ({ ...h.result.layout[0], title }));
  h.result.widgets = h.result.layout.map((config) => ({ ...h.result.widgets[0], ...config }));
  const g = enableGestures(h);
  await flush();
  await h.nodes.hrdBuilderOpen.emit('click');
  g.installRects();
  const [first, second] = g.grid.querySelectorAll('[data-hrd-edit-index]');
  assert.ok(
    !g.grid
      .querySelectorAll('button')
      .some((node) => ['⠿ Seret', '↑', '↓', '↔ Ukuran'].includes(node.textContent))
  );
  await g.grid.emit('pointerdown', g.pointer(first, 80, 170));
  await h.doc.emit('pointermove', g.pointer(first, 450, 170));
  await h.timers.run(16);
  assert.equal(g.grid.querySelectorAll('[data-hrd-edit-index]')[0], second);
  assert.equal(g.grid.querySelectorAll('[data-hrd-edit-index]')[1], first);
  assert.equal(second.animations.at(-1).options.duration, 200);
  await h.doc.emit('pointerup', g.pointer(first, 450, 170));
  await h.nodes.dashboardUndo.emit('click');
  assert.match(g.grid.querySelector('[data-hrd-edit-index]').textContent, /Satu/);
  h.dashboard.editor.close(false);
});

test('corner resize follows the pointer and commits one undoable size change', async (t) => {
  const h = dashboardHarness(t, { builder: true, unified: true });
  const g = enableGestures(h);
  await flush();
  await h.nodes.hrdBuilderOpen.emit('click');
  g.installRects();
  const card = g.grid.querySelector('[data-hrd-edit-index]');
  const handle = card.querySelectorAll('[data-dashboard-resize]').at(-1);
  await g.grid.emit('pointerdown', g.pointer(handle, 280, 340));
  await h.doc.emit('pointermove', g.pointer(handle, 600, 540));
  await h.timers.run(16);
  assert.equal(card.dataset.width, 2);
  assert.equal(card.dataset.height, 2);
  assert.match(card.style.width, /px$/);
  assert.equal(h.nodes.widgetFields.width.value, '2');
  await h.doc.emit('pointerup', g.pointer(handle, 600, 540));
  assert.equal(card.style.width, '');
  await h.nodes.dashboardUndo.emit('click');
  assert.equal(g.grid.querySelector('[data-hrd-edit-index]').dataset.width, 1);
  h.dashboard.editor.close(false);
});

test('rightmost cards can grow onto the next row and keyboard resizing keeps focus on the handle', async (t) => {
  const h = dashboardHarness(t, { builder: true, unified: true });
  h.result.layout = ['Satu', 'Dua', 'Tiga'].map((title) => ({ ...h.result.layout[0], title }));
  h.result.widgets = h.result.layout.map((config) => ({ ...h.result.widgets[0], ...config }));
  const g = enableGestures(h);
  await flush();
  await h.nodes.hrdBuilderOpen.emit('click');
  g.installRects();
  const card = g.grid.querySelectorAll('[data-hrd-edit-index]')[2];
  const handle = card.querySelector('[data-dashboard-resize]');
  await g.grid.emit('pointerdown', g.pointer(handle, 890, 200));
  await h.doc.emit('pointermove', g.pointer(handle, 1210, 200));
  await h.timers.run(16);
  assert.equal(card.dataset.width, 2);
  assert.equal(card.getBoundingClientRect().left, 0);
  assert.ok(card.getBoundingClientRect().right <= g.grid.getBoundingClientRect().right);
  await h.doc.emit('pointerup', g.pointer(handle, 1210, 200));
  await h.doc.emit('keydown', { target: handle, key: 'ArrowRight' });
  assert.equal(card.dataset.width, 3);
  assert.equal(h.doc.activeElement, handle);
  await h.doc.emit('keydown', { target: handle, key: 'ArrowLeft' });
  assert.equal(card.dataset.width, 2);
  h.dashboard.editor.close(false);
});

test('a cancelled gesture before the animation frame does not stall the next drag', async (t) => {
  const h = dashboardHarness(t, { builder: true, unified: true });
  h.result.layout = ['Satu', 'Dua'].map((title) => ({ ...h.result.layout[0], title }));
  h.result.widgets = h.result.layout.map((config) => ({ ...h.result.widgets[0], ...config }));
  const g = enableGestures(h);
  await flush();
  await h.nodes.hrdBuilderOpen.emit('click');
  g.installRects();
  const first = g.grid.querySelector('[data-hrd-edit-index]');
  await g.grid.emit('pointerdown', g.pointer(first, 80, 170));
  await h.doc.emit('pointermove', g.pointer(first, 450, 170));
  await h.doc.emit('keydown', { target: first, key: 'Escape' });
  await flush();
  await g.grid.emit('pointerdown', g.pointer(first, 80, 170));
  await h.doc.emit('pointermove', g.pointer(first, 450, 170));
  await h.timers.run(16);
  assert.match(g.grid.querySelector('[data-hrd-edit-index]').textContent, /Dua/);
  await h.doc.emit('pointerup', g.pointer(first, 450, 170));
  h.dashboard.editor.close(false);
});

test('Escape cancels a drag and keyboard reordering remains possible without motion or arrow controls', async (t) => {
  const h = dashboardHarness(t, { builder: true, unified: true });
  h.result.layout = ['Satu', 'Dua'].map((title) => ({ ...h.result.layout[0], title }));
  h.result.widgets = h.result.layout.map((config) => ({ ...h.result.widgets[0], ...config }));
  const g = enableGestures(h);
  await flush();
  await h.nodes.hrdBuilderOpen.emit('click');
  g.installRects();
  const first = g.grid.querySelector('[data-hrd-edit-index]');
  await g.grid.emit('pointerdown', g.pointer(first, 80, 170));
  await h.doc.emit('pointermove', g.pointer(first, 450, 170));
  await h.timers.run(16);
  await h.doc.emit('keydown', { target: first, key: 'Escape' });
  assert.equal(g.grid.querySelector('[data-hrd-edit-index]'), first);
  assert.equal(h.nodes.dashboardUndo.disabled, true);
  await h.doc.emit('keydown', { target: first, key: ' ' });
  await h.doc.emit('keydown', { target: first, key: 'ArrowRight' });
  await h.doc.emit('keydown', { target: first, key: 'Enter' });
  assert.match(g.grid.querySelector('[data-hrd-edit-index]').textContent, /Dua/);
  h.dashboard.editor.close(false);
});

test('reduced motion disables settling and neighbor animations but preserves direct dragging', async (t) => {
  const h = dashboardHarness(t, { builder: true, unified: true });
  h.result.layout = ['Satu', 'Dua'].map((title) => ({ ...h.result.layout[0], title }));
  h.result.widgets = h.result.layout.map((config) => ({ ...h.result.widgets[0], ...config }));
  const g = enableGestures(h, true);
  await flush();
  await h.nodes.hrdBuilderOpen.emit('click');
  g.installRects();
  const [first, second] = g.grid.querySelectorAll('[data-hrd-edit-index]');
  await g.grid.emit('pointerdown', g.pointer(first, 80, 170));
  await h.doc.emit('pointermove', g.pointer(first, 450, 170));
  await h.timers.run(16);
  await h.doc.emit('pointerup', g.pointer(first, 450, 170));
  assert.equal(g.grid.querySelector('[data-hrd-edit-index]'), second);
  assert.equal(second.animations, undefined);
  h.dashboard.editor.close(false);
});

test('live settings update the canvas immediately without a submit or a permanent write', async (t) => {
  const h = dashboardHarness(t, { builder: true, unified: true });
  await flush();
  await h.nodes.hrdBuilderOpen.emit('click');
  await h.nodes.hrdWidgets.children[0].querySelector('[data-hrd-settings-open]').emit('click');
  const title = h.nodes.widgetFields.title;
  title.focus();
  title.value = 'Grafik langsung';
  h.nodes.widgetFields.chart.value = 'donut';
  h.nodes.widgetFields.width.value = '2';
  await h.nodes.hrdWidgetForm.emit('input');

  assert.match(h.nodes.hrdWidgets.children[0].textContent, /Grafik langsung/);
  assert.equal(h.nodes.hrdWidgets.children[0].dataset.width, 2);
  assert.equal(h.doc.activeElement, title);
  assert.equal(h.nodes.hrdWidgetSettings.hidden, false);
  assert.ok(h.calls.every((call) => !['PATCH', 'PUT'].includes(call.options.method)));
  await h.timers.run(300);
  const preview = h.calls.find((call) => call.url.startsWith('/dashboard/preview'));
  assert.equal(JSON.parse(preview.options.body).widgets[0].chart, 'donut');
  assert.equal(h.nodes.hrdWidgetSettings.hidden, false);
  h.dashboard.editor.close(false);
});

test('live editing refreshes draft data every four seconds without replacing focused settings or writing preferences', async (t) => {
  const h = dashboardHarness(t, { builder: true, unified: true });
  await flush();
  await h.nodes.hrdBuilderOpen.emit('click');
  await h.nodes.hrdWidgets.children[0].querySelector('[data-hrd-settings-open]').emit('click');
  h.nodes.widgetFields.title.value = 'Tetap di kanvas';
  h.nodes.widgetFields.title.focus();
  await h.nodes.hrdWidgetForm.emit('input');
  await h.timers.run(300);
  const card = h.nodes.hrdWidgets.children[0];
  await h.timers.run(4000);

  assert.equal(h.nodes.hrdWidgets.children[0], card);
  assert.equal(h.doc.activeElement, h.nodes.widgetFields.title);
  assert.equal(h.nodes.widgetFields.title.value, 'Tetap di kanvas');
  assert.equal(h.calls.filter((call) => call.url.startsWith('/dashboard/preview')).length, 2);
  assert.equal(h.calls.filter((call) => call.url.startsWith('/dashboard/data')).length, 1);
  assert.ok(h.calls.every((call) => !call.url.startsWith('/dashboard/saved')));
  h.dashboard.editor.close(false);
});

test('changing company during live editing retains the draft and sends new previews in the selected scope without navigation', async (t) => {
  const h = dashboardHarness(t, { builder: true, unified: true });
  await flush();
  await h.nodes.hrdBuilderOpen.emit('click');
  await h.nodes.hrdWidgets.children[0].querySelector('[data-hrd-settings-open]').emit('click');
  h.nodes.widgetFields.title.value = 'Susunan lintas perusahaan';
  await h.nodes.hrdWidgetForm.emit('input');
  h.nodes.dashboardContext.value = 'company-b';
  await h.nodes.dashboardContext.emit('change');
  await h.timers.run(300);

  assert.match(h.calls.at(-1).url, /company=company-b/);
  assert.match(h.win.location.href, /company=company-b/);
  assert.equal(
    JSON.parse(h.calls.at(-1).options.body).widgets[0].title,
    'Susunan lintas perusahaan'
  );
  assert.equal(h.nodes.widgetFields.title.value, 'Susunan lintas perusahaan');
  assert.equal(h.root.dataset.editing, 'true');
  h.dashboard.editor.close(false);
});

test('saving an existing dashboard uses its original version and does not create a replacement dashboard', async (t) => {
  const h = dashboardHarness(t, { builder: true, unified: true });
  await flush();
  await h.nodes.hrdBuilderOpen.emit('click');
  await h.nodes.hrdWidgets.children[0].querySelector('[data-hrd-settings-open]').emit('click');
  h.nodes.widgetFields.title.value = 'Disimpan langsung';
  await h.nodes.hrdWidgetForm.emit('input');
  await h.nodes.hrdBuilderSave.emit('click');

  const saved = h.calls.find((call) => call.options.method === 'PATCH');
  assert.match(saved.url, /\/dashboard\/saved\/dashboard-one/);
  assert.equal(JSON.parse(saved.options.body).version, 1);
  assert.equal(JSON.parse(saved.options.body).widgets[0].title, 'Disimpan langsung');
  assert.equal(h.nodes.hrdBuilder.hidden, true);
  assert.ok(
    !h.calls.some(
      (call) => call.options.method === 'POST' && call.url.startsWith('/dashboard/saved')
    )
  );
});

test('save as creates a separate named dashboard and cancellation preserves the unsaved canvas', async (t) => {
  const h = dashboardHarness(t, { builder: true, unified: true });
  await flush();
  await h.nodes.hrdBuilderOpen.emit('click');
  const pending = h.nodes.dashboardSaveAs.emit('click');
  await flush();
  assert.equal(h.nodes.dashboardNameDialog.open, true);
  await h.nodes.dashboardNameCancel.emit('click');
  await pending;
  assert.equal(h.root.dataset.editing, 'true');
  assert.ok(!h.calls.some((call) => call.url.startsWith('/dashboard/saved')));

  const saving = h.nodes.dashboardSaveAs.emit('click');
  await flush();
  h.nodes.dashboardNameForm.elements.namedItem('name').value = 'Dashboard kedua';
  await h.nodes.dashboardNameForm.emit('submit');
  await saving;
  const saved = h.calls.find((call) => call.url.startsWith('/dashboard/saved'));
  assert.equal(saved.options.method, 'POST');
  assert.equal(JSON.parse(saved.options.body).name, 'Dashboard kedua');
  assert.equal(h.nodes.hrdBuilder.hidden, true);
});

test('live preview requests remain serial and an old response cannot replace a newer edit', async (t) => {
  const h = dashboardHarness(t, { builder: true, unified: true });
  await flush();
  await h.nodes.hrdBuilderOpen.emit('click');
  await h.nodes.hrdWidgets.children[0].querySelector('[data-hrd-settings-open]').emit('click');
  const pending = deferred();
  let requests = 0;
  t.mock.method(globalThis, 'fetch', async (_url, options) => {
    requests++;
    const widgets = JSON.parse(options.body).widgets;
    if (requests === 1) await pending.promise;
    return {
      ok: true,
      status: 200,
      headers: { get: () => 'application/json' },
      json: async () => ({
        ...h.result,
        widgets: widgets.map((widget) => ({ ...h.result.widgets[0], ...widget })),
      }),
    };
  });
  h.nodes.widgetFields.title.value = 'Versi lama';
  await h.nodes.hrdWidgetForm.emit('input');
  const [id, task] = [...h.timers.tasks].find(([, task]) => task.delay === 300);
  h.timers.tasks.delete(id);
  const running = task.callback();
  await flush();
  h.nodes.widgetFields.title.value = 'Versi terbaru';
  await h.nodes.hrdWidgetForm.emit('input');
  assert.equal(requests, 1);
  pending.resolve();
  await running;
  await h.timers.run(300);

  assert.equal(requests, 2);
  assert.match(h.nodes.hrdWidgets.children[0].textContent, /Versi terbaru/);
  assert.equal([...h.timers.tasks.values()].filter((task) => task.delay === 4000).length, 1);
  h.dashboard.editor.close(false);
});

test('changing a source does not temporarily present the old sources values as the new source', async (t) => {
  const h = dashboardHarness(t, { builder: true, unified: true });
  h.result.sources.push({
    id: 'department',
    title: 'Departemen',
    kind: 'choice',
    group: 'Demografi',
  });
  await flush();
  await h.nodes.hrdBuilderOpen.emit('click');
  await h.nodes.hrdWidgets.children[0].querySelector('[data-hrd-settings-open]').emit('click');
  h.nodes.widgetFields.source.value = 'department';
  await h.nodes.hrdWidgetForm.emit('input');

  assert.match(h.nodes.hrdWidgets.children[0].textContent, /Pratinjau akan muncul/);
  assert.ok(!h.nodes.hrdWidgets.children[0].textContent.includes('<script>Pria</script>'));
  h.dashboard.editor.close(false);
});

test('view-mode updates preserve unaffected cards and focused graph controls instead of rebuilding the canvas', async (t) => {
  const h = dashboardHarness(t, { builder: true, unified: true });
  await flush();
  const card = h.nodes.hrdWidgets.children[0];
  const control = card.querySelector('button');
  control.focus();
  h.result.widgets[0].rows[0].count = 2;
  await h.timers.run(4000);

  assert.equal(h.nodes.hrdWidgets.children[0], card);
  assert.equal(h.doc.activeElement.dataset.crossSource, 'gender');
});

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
