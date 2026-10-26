import {
  animateDashboardLayout,
  captureDashboardRects,
  setupDashboardGestures,
} from './dashboard-gestures.js';

export function dashboardRateLimitError(response) {
  const header = response.headers.get('Retry-After');
  const delay =
    header && /^\d+(?:\.\d+)?$/.test(header.trim())
      ? Number(header)
      : (Date.parse(header) - Date.now()) / 1000;
  const retryAfter = Number.isFinite(delay) ? Math.max(1, Math.ceil(delay)) : 60;
  return Object.assign(
    new Error(`Terlalu banyak permintaan. Coba kembali dalam ${retryAfter} detik.`),
    { status: 429, retryAfter }
  );
}

export function startHrdPolling({
  load,
  apply,
  error,
  document: doc = document,
  interval = 4000,
  timeout = 30000,
  timers = window,
}) {
  let timer;
  let running = false;
  let requested = false;
  let stopped = false;
  let paused = false;
  let controller;
  let revision = 0;
  let coolingDown = false;
  let cooldownTimer;
  const run = async () => {
    if (stopped || paused || doc.hidden || coolingDown) return;
    if (running) {
      requested = true;
      return;
    }
    timers.clearTimeout(timer);
    running = true;
    requested = false;
    const version = revision;
    controller = new AbortController();
    let timedOut = false;
    const timeoutTimer = timers.setTimeout(() => {
      timedOut = true;
      controller.abort();
    }, timeout);
    try {
      const data = await load(controller.signal);
      if (version === revision && !doc.hidden && !stopped && !paused) apply(data);
    } catch (exception) {
      if (
        version === revision &&
        !stopped &&
        !paused &&
        !doc.hidden &&
        (timedOut || exception.name !== 'AbortError')
      ) {
        if (exception.status === 429) {
          coolingDown = true;
          cooldownTimer = timers.setTimeout(() => {
            coolingDown = false;
            run();
          }, exception.retryAfter * 1000);
        }
        error(
          timedOut ? new Error('Permintaan terlalu lama. Mencoba kembali otomatis.') : exception
        );
      }
    } finally {
      timers.clearTimeout(timeoutTimer);
      running = false;
      if (!stopped && !paused && !doc.hidden && !coolingDown)
        timer = timers.setTimeout(run, requested ? 0 : interval);
    }
  };
  const refresh = () => {
    revision++;
    controller?.abort();
    timers.clearTimeout(timer);
    run();
  };
  const visibility = () => {
    if (doc.hidden) {
      controller?.abort();
      timers.clearTimeout(timer);
    } else refresh();
  };
  doc.addEventListener('visibilitychange', visibility);
  run();
  return {
    refresh,
    pause() {
      paused = true;
      revision++;
      controller?.abort();
      timers.clearTimeout(timer);
    },
    resume() {
      paused = false;
      refresh();
    },
    stop() {
      stopped = true;
      controller?.abort();
      timers.clearTimeout(timer);
      timers.clearTimeout(cooldownTimer);
      doc.removeEventListener('visibilitychange', visibility);
    },
  };
}

export function moveHrdWidget(widgets, from, to) {
  const next = [...widgets];
  if (from < 0 || to < 0 || from >= next.length || to >= next.length) return next;
  next.splice(to, 0, next.splice(from, 1)[0]);
  return next;
}

export function hrdRowValue(widget, row) {
  if (Object.hasOwn(row, 'value')) return row.value;
  return widget.metric === 'percent' ? row.percent : row.count;
}

export function hrdWidgetValue(widget) {
  if (['average', 'min', 'max'].includes(widget.metric))
    return widget.numeric?.[widget.metric] ?? null;
  if (widget.metric === 'percent')
    return widget.kind === 'laboratory' ? widget.abnormal_percent : widget.total ? 100 : null;
  return widget.total;
}

export function createHrdWidget(source) {
  return {
    source: source.id,
    title: source.title,
    dimension: '',
    filter_source: '',
    filter_value: '',
    chart: ['number', 'kpi'].includes(source.kind)
      ? 'number'
      : ['text', 'operation_table'].includes(source.kind)
        ? 'table'
        : source.kind === 'laboratory'
          ? 'indicator'
          : 'bar',
    metric: source.kind === 'number' ? 'average' : 'count',
    width: source.kind === 'operation_table' ? 3 : 1,
    height: 1,
  };
}

export function filterHrdCatalog(sources, query = '', group = '') {
  const search = query.trim().toLocaleLowerCase('id');
  return sources.filter(
    (source) =>
      (!group || source.group === group) &&
      (!search ||
        `${source.title} ${source.group} ${source.unit ?? ''}`
          .toLocaleLowerCase('id')
          .includes(search))
  );
}

export function createHrdChartRenderer(doc, setCross = () => {}) {
  const colors = ['#2563eb', '#059669', '#db2777', '#7c3aed', '#0891b2', '#d97706', '#475569'];
  const formatNumber = (value) =>
    value === null || value === undefined
      ? '—'
      : Number(value).toLocaleString('id-ID', { maximumFractionDigits: 2 });
  const el = (tag, text = '', className = '') => {
    const node = doc.createElement(tag);
    node.textContent = text;
    node.className = className;
    return node;
  };
  const button = (text, action, className = 'hrd-button') => {
    const node = el('button', text, className);
    node.type = 'button';
    node.addEventListener('click', action);
    return node;
  };
  const table = (widget) => {
    const node = el('table', '', 'hrd-chart-table');
    const head = el('thead');
    const header = el('tr');
    [
      'Kelompok',
      'Jumlah',
      widget.metric === 'count'
        ? 'Persentase'
        : { percent: 'Persentase', average: 'Rata-rata', min: 'Minimum', max: 'Maksimum' }[
            widget.metric
          ],
    ].forEach((value) => header.append(el('th', value)));
    head.append(header);
    node.append(head);
    const body = el('tbody');
    widget.rows.forEach((row) => {
      const tr = el('tr');
      const label = el('td');
      label.append(
        button(
          row.label,
          () => setCross(row.cross_source ?? widget.source, row.cross_value ?? row.label),
          'text-left min-h-11 break-words text-slate-700 underline decoration-slate-200 underline-offset-4'
        )
      );
      if (row.context) label.append(el('small', row.context, 'block text-xs text-slate-500'));
      const value = widget.metric === 'count' ? row.percent : hrdRowValue(widget, row);
      tr.append(
        label,
        el('td', formatNumber(row.count)),
        el(
          'td',
          value === null
            ? '—'
            : `${formatNumber(value)}${['count', 'percent'].includes(widget.metric) ? '%' : ''}`
        )
      );
      body.append(tr);
    });
    node.append(body);
    return node;
  };
  const bars = (widget) => {
    const node = el('div', '', 'grid gap-3');
    const values = widget.rows.map((row) => hrdRowValue(widget, row) ?? 0);
    const min = Math.min(0, ...values);
    const max = Math.max(1, ...values);
    const range = max - min;
    widget.rows.slice(0, 20).forEach((row, index) => {
      const rowButton = button(
        '',
        () => setCross(row.cross_source ?? widget.source, row.cross_value ?? row.label),
        'hrd-chart-row'
      );
      const value = hrdRowValue(widget, row);
      rowButton.dataset.crossSource = row.cross_source ?? widget.source;
      rowButton.dataset.crossValue = row.cross_value ?? row.label;
      const label = el('span', '', 'hrd-chart-label');
      label.append(
        el('span', row.label),
        el(
          'strong',
          value === null ? '—' : `${formatNumber(value)}${widget.metric === 'percent' ? '%' : ''}`
        )
      );
      const track = el('span', '', 'hrd-chart-track');
      const fill = el('span', '', 'hrd-chart-fill');
      fill.style.display = 'block';
      fill.style.width = `${(Math.abs(value ?? 0) / range) * 100}%`;
      fill.style.left = `${(((value ?? 0) < 0 ? (value ?? 0) - min : -min) / range) * 100}%`;
      fill.style.background = colors[index % colors.length];
      track.append(fill);
      rowButton.append(label, track);
      node.append(rowButton);
    });
    if (widget.rows.length > 20)
      node.append(
        el('p', '20 kelompok teratas. Buka detail untuk seluruh data.', 'text-xs text-slate-500')
      );
    return node;
  };
  const chart = (widget) => {
    const body = el('div', '', 'hrd-widget-body');
    if (widget.kind === 'kpi') {
      body.append(
        el('strong', formatNumber(widget.total), 'hrd-kpi-value'),
        el(
          'p',
          widget.total === null
            ? 'Belum tersedia pada cakupan data ini'
            : widget.source === 'stat:remaining'
              ? 'Target dikurangi peserta dengan hasil final'
              : 'Peserta pada cakupan data terpilih',
          'text-xs leading-5 text-slate-500'
        )
      );
      return body;
    }
    if (!widget.total) {
      body.append(
        el(
          'p',
          'Belum ada data / hasil yang dirilis.',
          'self-center text-center text-sm text-slate-500'
        )
      );
      return body;
    }
    if (widget.chart === 'number') {
      const value = hrdWidgetValue(widget);
      const suffix = widget.metric === 'percent' && value !== null ? '%' : '';
      body.append(
        el('strong', formatNumber(value) + suffix, 'text-4xl font-bold'),
        el(
          'p',
          `${{ count: 'Hasil tercatat', percent: widget.kind === 'laboratory' ? 'Hasil tidak normal dari yang dapat dibandingkan' : 'Hasil tercatat (%)', average: 'Rata-rata', min: 'Minimum', max: 'Maksimum' }[widget.metric]} ${widget.unit ?? ''}`,
          'text-sm text-slate-500'
        )
      );
      if (widget.numeric) {
        const stat = widget.numeric;
        body.append(
          el(
            'p',
            `${stat.count} hasil angka valid · rentang ${formatNumber(stat.min)}–${formatNumber(stat.max)}`,
            'text-xs text-slate-500'
          )
        );
      }
    } else if (widget.chart === 'indicator' && widget.kind === 'laboratory') {
      body.append(
        el(
          'strong',
          widget.abnormal_percent === null ? '—' : `${widget.abnormal_percent}%`,
          'text-3xl font-bold text-[#00008B]'
        ),
        el(
          'p',
          `Tidak normal · ${widget.valid} hasil dapat dibandingkan`,
          'text-xs text-slate-500'
        ),
        bars(widget)
      );
    } else if (['pie', 'donut', 'stacked'].includes(widget.chart)) {
      const rows = widget.rows.filter(
        (row) => widget.kind !== 'laboratory' || row.percent !== null
      );
      const weight = (row) => (widget.kind === 'laboratory' ? row.percent : row.count);
      const total = rows.reduce((sum, row) => sum + weight(row), 0);
      let position = 0;
      const slices = rows.map((row, index) => {
        const start = position;
        position += (weight(row) / total) * 100;
        return `${colors[index % colors.length]} ${start}% ${position}%`;
      });
      const ring = el('div', '', 'hrd-chart-ring');
      ring.dataset.donut = String(widget.chart === 'donut');
      ring.setAttribute('role', 'img');
      ring.setAttribute('aria-label', `${widget.title}: ${widget.total} hasil`);
      if (
        widget.chart === 'stacked' &&
        widget.dimension &&
        widget.rows.some((row) => row.series?.length)
      ) {
        const seriesLabels = [
          ...new Set(widget.rows.flatMap((row) => row.series.map((series) => series.label))),
        ];
        const stacked = el('div', '', 'grid gap-4');
        widget.rows.forEach((row) => {
          const group = el('div', '', 'grid gap-2');
          group.append(
            button(
              row.label,
              () => setCross(row.cross_source, row.cross_value),
              'min-h-11 text-left text-sm font-semibold text-slate-600'
            )
          );
          const track = el('div', '', 'flex h-11 w-full overflow-hidden rounded-lg');
          row.series.forEach((series) => {
            const slice = button(
              formatNumber(series.count),
              () => setCross(widget.source, series.label),
              'h-full min-w-0 overflow-hidden text-xs font-semibold text-white'
            );
            slice.style.width = `${(series.count / row.count) * 100}%`;
            slice.style.background = colors[seriesLabels.indexOf(series.label) % colors.length];
            slice.setAttribute('aria-label', `${row.label} · ${series.label}: ${series.count}`);
            track.append(slice);
          });
          group.append(track);
          stacked.append(group);
        });
        body.append(
          stacked,
          el('p', seriesLabels.join(' · '), 'text-xs text-slate-500'),
          table(widget)
        );
      } else {
        if (widget.chart === 'stacked') {
          ring.className = 'flex h-11 w-full overflow-hidden rounded-lg';
          rows.forEach((row, index) => {
            const slice = button(
              '',
              () => setCross(row.cross_source ?? widget.source, row.cross_value ?? row.label),
              'h-full min-w-0'
            );
            slice.style.width = `${(weight(row) / total) * 100}%`;
            slice.style.background = colors[index % colors.length];
            slice.setAttribute('aria-label', `${row.label}: ${row.count}`);
            ring.append(slice);
          });
        } else
          ring.style.background = slices.length ? `conic-gradient(${slices.join(',')})` : '#e2e8f0';
        body.append(ring, table(widget));
      }
    } else
      body.append(
        widget.chart === 'table' || widget.kind === 'text' ? table(widget) : bars(widget)
      );
    if (widget.kind === 'laboratory')
      body.append(
        el(
          'p',
          `Normal/tidak normal: ${widget.valid} hasil dapat dibandingkan. Hasil kosong, tidak dilakukan, dan tidak dapat dibandingkan tidak masuk penyebut persentase.`,
          'text-xs leading-5 text-slate-500'
        )
      );
    return body;
  };
  return { chart, table };
}

export function setupHrdDashboard(root) {
  const doc = root.ownerDocument;
  const filters = root.querySelector('[data-hrd-filters]');
  const grid = root.querySelector('[data-hrd-widgets]');
  const tbody = root.querySelector('[data-hrd-participants]');
  const all = root.querySelector('[data-hrd-all]');
  const exportButton = root.querySelector('[data-hrd-export]');
  const error = root.querySelector('[data-hrd-error]');
  const confirm = root.querySelector('[data-hrd-confirm]');
  const detail = root.querySelector('[data-hrd-detail]');
  const labels = {
    belum: 'Belum mulai',
    proses: 'Dalam proses',
    siap_finalisasi: 'Siap finalisasi',
    selesai: 'Final · belum dirilis',
    batal: 'Batal',
    tidak_hadir: 'Tidak hadir',
  };
  let data;
  let page = 1;
  let cross;
  let exportState;
  let exporting = false;
  let submittingExport = false;
  let csvBusy = false;
  let csvRequest;
  const csvButton = root.querySelector('[data-hrd-recap]');
  const cooldowns = new Map();
  let activeFilters = {};
  let confirmedExport;
  let widgetSignature;
  let participantSignature;
  let editing = false;
  let detailRequest;
  let detailVersion = 0;
  let contextVersion = 0;
  let finishEditing = () => {};
  const selected = new Set();
  const widgetCards = new Map();
  const participantRows = new Map();
  const el = (tag, text = '', className = '') => {
    const node = doc.createElement(tag);
    node.textContent = text;
    node.className = className;
    return node;
  };
  const button = (text, action, className = 'hrd-button') => {
    const node = el('button', text, className);
    node.type = 'button';
    node.addEventListener('click', action);
    return node;
  };
  const params = () => {
    const query = new URLSearchParams({
      company: root.dataset.company,
      page: String(page),
      workspace: '1',
    });
    for (const [field, value] of Object.entries(activeFilters)) {
      if (root.dataset.reportsOnly === 'true' && field === 'visit') continue;
      if (value) query.set(field, value);
    }
    if (root.dataset.scopeVisit) query.set('visit', root.dataset.scopeVisit);
    if (root.dataset.scopeStatus) query.set('visit_status', root.dataset.scopeStatus);
    if (cross) {
      query.set('cross_source', cross.source);
      query.set('cross_value', cross.value);
    }
    return query;
  };
  const request = async (url, options = {}, format = 'json') => {
    const response = await fetch(url, {
      credentials: 'same-origin',
      ...options,
      headers: {
        Accept: format === 'csv' ? 'application/json, text/csv;q=0.9' : 'application/json',
        'X-CSRF-TOKEN': filters.elements.namedItem('_token').value,
        ...options.headers,
      },
    });
    if ([401, 419].includes(response.status))
      throw Object.assign(new Error('Sesi berakhir. Silakan login kembali.'), { revoked: true });
    if ([403, 404].includes(response.status))
      throw Object.assign(new Error('Akses data tidak lagi tersedia.'), { revoked: true });
    if (response.status === 429) throw dashboardRateLimitError(response);
    if (format === 'csv' && response.ok) {
      if (!response.headers.get('content-type')?.toLowerCase().startsWith('text/csv'))
        throw new Error(
          'Server tidak mengirim file CSV. Unduhan dibatalkan; silakan coba kembali.'
        );
      return response.blob();
    }
    if (!response.headers.get('content-type')?.includes('application/json'))
      throw new Error('Server belum dapat memproses permintaan.');
    const payload = await response.json();
    if (!response.ok)
      throw new Error(
        Object.values(payload.errors ?? {})
          .flat()
          .join(' ') ||
          payload.message ||
          'Permintaan gagal.'
      );
    return payload;
  };
  const showError = (message) => {
    error.textContent = message;
    error.hidden = false;
  };
  const setCross = (source, value) => {
    cross = { source, value };
    page = 1;
    selected.clear();
    all.checked = false;
    const clear = root.querySelector('[data-hrd-cross-clear]');
    clear.hidden = false;
    clear.textContent = `Filter grafik: ${value} · Hapus ×`;
    poller.refresh();
  };
  const { chart, table } = createHrdChartRenderer(doc, setCross);
  const selectionChanged = () => {
    const count = all.checked ? (data?.stats.released ?? 0) : selected.size;
    root.querySelector('[data-hrd-selected]').textContent =
      `${count} peserta dipilih${all.checked ? ' di seluruh halaman' : ''}`;
    exportButton.disabled =
      exporting || submittingExport || cooldowns.has('zip') || count === 0 || count > 1000;
    csvButton.disabled = csvBusy || cooldowns.has('csv') || !data;
  };
  const coolDown = (kind, seconds) => {
    const target = kind === 'zip' ? exportButton : csvButton;
    const entry = { remaining: seconds, label: target.textContent };
    const update = () => {
      if (entry.remaining <= 0) {
        cooldowns.delete(kind);
        target.textContent = entry.label;
        selectionChanged();
        return;
      }
      target.textContent = `Coba ${kind.toUpperCase()} dalam ${entry.remaining} detik`;
      selectionChanged();
      entry.timer = window.setTimeout(() => {
        entry.remaining--;
        update();
      }, 1000);
    };
    cooldowns.set(kind, entry);
    update();
  };
  const renderWidgets = () => {
    const signature = JSON.stringify(data.widgets);
    if (signature === widgetSignature || editing) return;
    widgetSignature = signature;
    grid.querySelector('[data-hrd-empty]')?.remove();
    const keys = new Set();
    const defaultStats = root.querySelector('[data-dashboard-default-stats]');
    if (defaultStats) defaultStats.hidden = data.personal_layout || editing;
    const visibleWidgets = data.personal_layout
      ? data.widgets
      : data.widgets.filter((widget) => widget.kind !== 'kpi');
    visibleWidgets.forEach((widget, index) => {
      const key = `${index}:${widget.source}`;
      keys.add(key);
      let state = widgetCards.get(key);
      if (!state) {
        const card = el('article', '', 'hrd-widget');
        const heading = el('div', '', 'hrd-widget-heading');
        const title = el('div');
        const name = el('h3', '', 'hrd-widget-title');
        const subtitle = el('p', '', 'hrd-widget-subtitle');
        title.append(name, subtitle);
        state = { card, name, subtitle, widget, signature: null };
        widgetCards.set(key, state);
        const open = button('→', () => {
          detail.querySelector('h2').textContent = state.widget.title;
          detail
            .querySelector('[data-hrd-detail-content]')
            .replaceChildren(
              el(
                'p',
                'Klik kelompok untuk memfilter dashboard dan daftar peserta.',
                'text-sm text-slate-500'
              ),
              table(state.widget)
            );
          detail.showModal();
        });
        open.setAttribute('aria-label', `Detail ${widget.title}`);
        heading.append(title, open);
        open.hidden = widget.kind === 'kpi';
        card.append(heading);
      }
      state.widget = widget;
      state.card.dataset.width = widget.width;
      state.card.dataset.height = widget.height;
      state.card.dataset.kind = widget.kind;
      state.name.textContent = widget.title;
      state.subtitle.textContent = [
        widget.group,
        widget.dimension_title,
        widget.unit,
        widget.reference,
      ]
        .filter(Boolean)
        .join(' · ');
      const rowSignature = JSON.stringify(widget);
      if (state.signature !== rowSignature) {
        const focused = state.card.contains(doc.activeElement)
          ? {
              source: doc.activeElement.dataset.crossSource,
              value: doc.activeElement.dataset.crossValue,
            }
          : null;
        const scroll = state.body?.scrollTop ?? 0;
        const body = chart(widget);
        state.body ? state.body.replaceWith(body) : state.card.append(body);
        state.body = body;
        state.signature = rowSignature;
        body.scrollTop = scroll;
        if (focused?.source)
          [...body.querySelectorAll('[data-cross-source]')]
            .find(
              (node) =>
                node.dataset.crossSource === focused.source &&
                node.dataset.crossValue === focused.value
            )
            ?.focus({ preventScroll: true });
      }
      if (grid.children[index] !== state.card)
        grid.insertBefore(state.card, grid.children[index] ?? null);
    });
    widgetCards.forEach((state, key) => {
      if (!keys.has(key)) {
        state.card.remove();
        widgetCards.delete(key);
      }
    });
    if (!visibleWidgets.length) {
      const empty = el('div', '', 'hrd-empty');
      empty.dataset.hrdEmpty = '';
      empty.append(
        el('h3', 'Ruang untuk prioritas Anda', 'text-lg font-bold text-slate-800'),
        el(
          'p',
          'Klik Atur dashboard, lalu tambahkan widget dari katalog.',
          'mt-2 text-sm text-slate-500'
        )
      );
      grid.append(empty);
    }
  };
  const openParticipant = async (row) => {
    detailRequest?.abort();
    detailRequest = new AbortController();
    const version = ++detailVersion;
    const content = detail.querySelector('[data-hrd-detail-content]');
    detail.querySelector('h2').textContent = row.name;
    content.replaceChildren(el('p', 'Memuat hasil yang sudah dirilis…', 'text-sm text-slate-500'));
    detail.showModal();
    try {
      const result = await request(
        `${root.dataset.participantUrl.replace('00000000-0000-0000-0000-000000000000', row.id)}?company=${root.dataset.company}`,
        { signal: detailRequest.signal }
      );
      if (version !== detailVersion || !detail.open) return;
      const nodes = [
        el('p', `${result.number} · ${result.department ?? '—'}`, 'text-sm text-slate-500'),
      ];
      [
        ['Kelayakan kerja', result.fitness],
        ['Kesimpulan', result.conclusion],
        ['Saran', result.advice],
      ].forEach(([title, value]) => {
        const section = el('section');
        section.append(
          el('h3', title, 'font-bold'),
          el(
            'p',
            value ?? 'Belum diisi',
            'mt-2 whitespace-pre-line break-words text-sm leading-6 text-slate-600'
          )
        );
        nodes.push(section);
      });
      result.sections.forEach((section) => {
        const block = el('section', '', 'border-t border-slate-200 pt-4');
        block.append(el('h3', section.title, 'font-bold'));
        section.groups.forEach((group) => {
          if (group.name) block.append(el('h4', group.name, 'mt-3 text-sm font-semibold'));
          const table = el('table', '', 'hrd-chart-table');
          const body = el('tbody');
          group.rows.forEach((row) => {
            const tr = el('tr');
            tr.append(
              el('td', row.label),
              el(
                'td',
                `${row.value ?? '—'}${row.unit ? ` ${row.unit}` : ''}${row.reference ? ` · Rujukan: ${row.reference}` : ''}${row.remark ? ` · ${row.remark}` : ''}`,
                'whitespace-pre-line'
              )
            );
            body.append(tr);
          });
          table.append(body);
          block.append(table);
        });
        if (section.doctor)
          block.append(
            el('p', `Dokter pemeriksa: ${section.doctor}`, 'mt-3 text-xs text-slate-500')
          );
        if (section.attachments.length)
          block.append(
            el(
              'p',
              `Lampiran tersedia dalam ZIP: ${section.attachments.map((attachment) => attachment.label).join(', ')}`,
              'mt-3 text-xs text-slate-500'
            )
          );
        nodes.push(block);
      });
      content.replaceChildren(...nodes);
    } catch (exception) {
      if (exception.name !== 'AbortError' && version === detailVersion)
        content.replaceChildren(el('p', exception.message, 'text-sm text-red-700'));
    }
  };
  const participantUrl = (row) => {
    const target = new URL(row.examination_url, window.location.href);
    const context = new URL(window.location.href).searchParams;
    for (const field of ['dashboard', 'company', 'visit', 'visit_status']) {
      if (context.has(field)) target.searchParams.set(field, context.get(field));
    }
    target.searchParams.set('from_dashboard', '1');
    return target.toString();
  };
  const renderParticipants = () => {
    const signature = JSON.stringify([
      data.participants,
      data.filtered_count,
      data.stats.released,
      data.pages,
      page,
      all.checked,
    ]);
    if (signature === participantSignature) return;
    participantSignature = signature;
    tbody.querySelector('[data-empty-row]')?.remove();
    const keys = new Set();
    data.participants.forEach((row, index) => {
      keys.add(row.id);
      let state = participantRows.get(row.id);
      if (!state) {
        const tr = el('tr');
        const selectCell = el('td', '', 'px-3 py-4');
        const label = el('label', '', 'flex h-11 w-11 cursor-pointer items-center justify-center');
        const checkbox = el('input');
        checkbox.type = 'checkbox';
        checkbox.className = 'h-4 w-4 accent-[#00008B]';
        label.append(checkbox);
        selectCell.append(label);
        const name = el('td', '', 'max-w-64 px-3 py-4');
        const open = el(
          'a',
          '',
          'min-h-11 text-left font-semibold text-[#00008B] underline decoration-slate-200 underline-offset-4'
        );
        const number = el('span', '', 'mt-1 block break-all text-xs text-slate-500');
        name.append(open, number);
        const packageCell = el('td', '', 'max-w-56 break-words px-3 py-4');
        const department = el('td', '', 'max-w-48 break-words px-3 py-4');
        const status = el('td', '', 'px-3 py-4 text-xs font-semibold');
        tr.append(selectCell, name, packageCell, department, status);
        state = { tr, checkbox, open, number, packageCell, department, status, row };
        participantRows.set(row.id, state);
        open.addEventListener('click', (event) => {
          if (!state.row.released || !state.row.examination_url) {
            event.preventDefault();
            if (state.row.released) openParticipant(state.row);
          } else open.href = participantUrl(state.row);
        });
        checkbox.addEventListener('change', () => {
          checkbox.checked ? selected.add(row.id) : selected.delete(row.id);
          selectionChanged();
        });
      }
      state.row = row;
      state.checkbox.disabled = !row.released || all.checked;
      if (!row.released) selected.delete(row.id);
      state.checkbox.checked = all.checked ? row.released : selected.has(row.id);
      state.checkbox.setAttribute('aria-label', `Pilih ${row.name}`);
      state.open.textContent = row.name;
      state.open.disabled = !row.released;
      state.open.setAttribute('aria-disabled', String(!row.released));
      state.open.tabIndex = row.released ? 0 : -1;
      if (row.released && row.examination_url) {
        state.open.href = participantUrl(row);
      } else state.open.removeAttribute('href');
      state.open.classList.toggle('text-slate-700', !row.released);
      state.open.title = row.released
        ? 'Lihat hasil yang sudah dirilis'
        : 'Hasil medis belum dirilis';
      state.number.textContent = row.number;
      state.packageCell.textContent = row.package;
      state.department.textContent = row.department;
      state.status.textContent = row.released
        ? 'Dirilis ke HRD'
        : (labels[row.status] ?? row.status);
      if (tbody.children[index] !== state.tr)
        tbody.insertBefore(state.tr, tbody.children[index] ?? null);
    });
    participantRows.forEach((state, id) => {
      if (!keys.has(id)) {
        state.tr.remove();
        participantRows.delete(id);
      }
    });
    if (!keys.size) {
      const row = el('tr');
      row.dataset.emptyRow = '';
      const cell = el(
        'td',
        'Tidak ada peserta sesuai filter.',
        'px-4 py-10 text-center text-slate-500'
      );
      cell.colSpan = 5;
      row.append(cell);
      tbody.append(row);
    }
    root.querySelector('[data-hrd-participant-count]').textContent =
      `${data.filtered_count} peserta · ${data.stats.released} laporan dirilis`;
    root.querySelector('[data-hrd-page]').textContent = `${data.page} / ${data.pages}`;
    root.querySelector('[data-hrd-prev]').disabled = page <= 1;
    root.querySelector('[data-hrd-next]').disabled = page >= data.pages;
  };
  const showExport = () => {
    if (!exportState) return;
    root.querySelector('[data-hrd-export-state]').hidden = false;
    root.querySelector('[data-hrd-export-status]').textContent =
      exportState.status === 'ready'
        ? `ZIP siap · ${exportState.total} peserta. Berlaku 24 jam sejak permintaan.`
        : exportState.message || `Menyiapkan ZIP: ${exportState.processed} / ${exportState.total}`;
    root.querySelector('[data-hrd-export-progress]').value = exportState.total
      ? (exportState.processed / exportState.total) * 100
      : 0;
    const link = root.querySelector('[data-hrd-download]');
    link.hidden = !exportState.download_url;
    if (exportState.download_url) link.href = exportState.download_url;
    exporting = ['queued', 'processing'].includes(exportState.status);
    selectionChanged();
  };
  const handleDataError = (exception) => {
    root.querySelector('[data-hrd-live]').textContent =
      exception.status === 429 ? 'Menunggu batas permintaan' : 'Koneksi terputus';
    if (exception.revoked) {
      data = null;
      exportState = null;
      selected.clear();
      widgetCards.clear();
      participantRows.clear();
      grid.replaceChildren();
      tbody.replaceChildren();
      detail.querySelector('[data-hrd-detail-content]').replaceChildren();
      detail.close();
      if (editing) finishEditing(false);
      root.querySelector('[data-hrd-catalog]')?.close();
      root.querySelector('[data-hrd-widget-settings]')?.close();
      root.querySelector('[data-hrd-builder-confirm]')?.close();
      const builderOpen = root.querySelector('[data-hrd-builder-open]');
      if (builderOpen) builderOpen.disabled = true;
      root.querySelectorAll('[data-hrd-stat]').forEach((node) => {
        node.textContent = '—';
      });
      root.querySelector('[data-hrd-download]').hidden = true;
      root.querySelector('[data-hrd-export-state]').hidden = true;
      exportButton.disabled = true;
      poller.stop();
    }
    showError(
      `${exception.message}${exception.revoked ? '' : ' Data terakhir tetap ditampilkan.'}`
    );
  };
  const poller = startHrdPolling({
    document: doc,
    load: async (signal) => request(`${root.dataset.dataUrl}?${params()}`, { signal }),
    apply: (result) => {
      data = result;
      page = result.page;
      if (!submittingExport)
        exportState =
          result.export ??
          (exportState
            ? {
                ...exportState,
                status: 'expired',
                download_url: null,
                message: 'ZIP kedaluwarsa. Buat ekspor baru.',
              }
            : null);
      error.hidden = true;
      root.querySelector('[data-hrd-live]').textContent = 'Terhubung · 4 detik';
      root.querySelector('[data-hrd-updated]').textContent =
        `Diperbarui ${new Date(result.updated_at).toLocaleTimeString('id-ID')} · otomatis setiap 4 detik`;
      root.querySelectorAll('[data-hrd-stat]').forEach((node) => {
        const value = result.stats[node.dataset.hrdStat];
        node.textContent = value === null ? 'Belum diatur' : value.toLocaleString('id-ID');
      });
      if (root.dataset.reportsOnly !== 'true') renderWidgets();
      renderParticipants();
      selectionChanged();
      showExport();
      updateOptions();
      const layoutNote = root.querySelector('[data-hrd-layout-note]');
      if (layoutNote)
        layoutNote.textContent = result.personal_layout
          ? 'Susunan pribadi Anda · tersimpan untuk akun ini.'
          : 'Tampilan awal perusahaan · sesuaikan menjadi dashboard pribadi Anda.';
      const builderOpen = root.querySelector('[data-hrd-builder-open]');
      if (builderOpen) builderOpen.disabled = false;
    },
    error: handleDataError,
  });
  const updateOptions = () => {
    if (!data?.options) return;
    const options = data.options;
    const sync = (field, entries, placeholder) => {
      const select = filters.elements.namedItem(field);
      const signature = JSON.stringify(entries);
      if (select.dataset.optionsSignature === signature) return;
      select.dataset.optionsSignature = signature;
      const value = select.value;
      const nodes = [
        Object.assign(el('option', placeholder), { value: '' }),
        ...entries.map(([value, label]) => Object.assign(el('option', label), { value })),
      ];
      if (value && !entries.some(([id]) => id === value))
        nodes.push(
          Object.assign(el('option', select.selectedOptions[0]?.textContent ?? value), { value })
        );
      select.replaceChildren(...nodes);
      select.value = value;
    };
    sync(
      'visit',
      options.map((visit) => [visit.id, visit.number]),
      'Semua kunjungan'
    );
    sync(
      'period',
      [...new Set(options.map((visit) => visit.period))].map((period) => [period, period]),
      'Semua periode'
    );
    const visit = filters.elements.namedItem('visit').value;
    const packages = new Map(
      options
        .filter((option) => !visit || option.id === visit)
        .flatMap((option) => option.packages)
        .map((packageItem) => [packageItem.id, packageItem.name])
    );
    sync('package', [...packages], 'Semua paket');
  };
  const resetSelection = () => {
    page = 1;
    selected.clear();
    all.checked = false;
    participantSignature = null;
  };
  const applyReportFilters = () => {
    activeFilters = Object.fromEntries(
      ['visit', 'period', 'package', 'q'].map((field) => [
        field,
        filters.elements.namedItem(field).value,
      ])
    );
    cross = null;
    root.querySelector('[data-hrd-cross-clear]').hidden = true;
    resetSelection();
    poller.refresh();
  };
  filters.addEventListener('submit', (event) => {
    event.preventDefault();
    applyReportFilters();
  });
  if (root.dataset.reportsOnly === 'true') {
    let searchTimer;
    filters.addEventListener('change', () => {
      window.clearTimeout(searchTimer);
      applyReportFilters();
    });
    filters.elements.namedItem('q').addEventListener('input', () => {
      window.clearTimeout(searchTimer);
      searchTimer = window.setTimeout(applyReportFilters, 300);
    });
  }
  filters.elements.namedItem('visit').addEventListener('change', () => {
    filters.elements.namedItem('package').value = '';
    updateOptions();
  });
  root.querySelector('[data-hrd-reset]').addEventListener('click', () => {
    filters.reset();
    activeFilters = {};
    cross = null;
    root.querySelector('[data-hrd-cross-clear]').hidden = true;
    resetSelection();
    updateOptions();
    poller.refresh();
  });
  root.querySelector('[data-hrd-cross-clear]').addEventListener('click', () => {
    cross = null;
    root.querySelector('[data-hrd-cross-clear]').hidden = true;
    resetSelection();
    poller.refresh();
  });
  root.querySelector('[data-hrd-prev]').addEventListener('click', () => {
    if (page > 1) {
      page--;
      poller.refresh();
    }
  });
  root.querySelector('[data-hrd-next]').addEventListener('click', () => {
    if (page < data.pages) {
      page++;
      poller.refresh();
    }
  });
  all.addEventListener('change', () => {
    selected.clear();
    renderParticipants();
    selectionChanged();
  });
  csvButton.addEventListener('click', async () => {
    if (csvBusy || cooldowns.has('csv') || !data) return;
    csvBusy = true;
    csvRequest = new AbortController();
    const version = contextVersion;
    selectionChanged();
    try {
      const blob = await request(
        `${root.dataset.recapUrl}?${params()}`,
        { signal: csvRequest.signal },
        'csv'
      );
      if (version !== contextVersion) return;
      error.hidden = true;
      const url = URL.createObjectURL(blob);
      const link = el('a');
      link.href = url;
      link.download = 'Rekap_MCU.csv';
      doc.body.append(link);
      link.click();
      link.remove();
      window.setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (exception) {
      if (version !== contextVersion || exception.name === 'AbortError') return;
      if (exception.status === 429) coolDown('csv', exception.retryAfter);
      if (exception.revoked) handleDataError(exception);
      else showError(exception.message);
    } finally {
      if (version === contextVersion) {
        csvBusy = false;
        selectionChanged();
      }
    }
  });
  root.querySelector('[data-hrd-company]')?.addEventListener('change', (event) => {
    const url = new URL(root.dataset.dashboardUrl ?? window.location.href, window.location.href);
    url.search = new URLSearchParams({ company: event.target.value });
    window.location.assign(url);
  });
  exportButton.addEventListener('click', () => {
    if (exportButton.disabled || cooldowns.has('zip')) return;
    confirmedExport = {
      query: params().toString(),
      body: { all: all.checked, ids: [...selected] },
    };
    root.querySelector('[data-hrd-confirm-description]').textContent =
      `Buat ZIP untuk ${all.checked ? data.stats.released : selected.size} peserta? Hanya hasil final dan sudah dirilis yang disertakan. File medis ini bersifat rahasia.`;
    confirm.showModal();
  });
  root.querySelector('[data-hrd-confirm-cancel]').addEventListener('click', () => confirm.close());
  root.querySelector('[data-hrd-confirm-submit]').addEventListener('click', async () => {
    if (exporting || submittingExport || cooldowns.has('zip') || !confirmedExport) return;
    submittingExport = true;
    const version = contextVersion;
    selectionChanged();
    confirm.close();
    try {
      const result = await request(`${root.dataset.exportUrl}?${confirmedExport.query}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(confirmedExport.body),
      });
      if (version !== contextVersion) return;
      exportState = result;
      showExport();
      poller.refresh();
    } catch (exception) {
      if (version === contextVersion) {
        if (exception.status === 429) coolDown('zip', exception.retryAfter);
        if (exception.revoked) handleDataError(exception);
        else showError(exception.message);
      }
    } finally {
      if (version === contextVersion) {
        submittingExport = false;
        selectionChanged();
      }
    }
  });
  root.querySelector('[data-hrd-detail-close]').addEventListener('click', () => detail.close());
  detail.addEventListener('click', (event) => {
    if (event.target.closest('button') && !event.target.matches('[data-hrd-detail-close]'))
      detail.close();
  });
  detail.addEventListener('close', () => {
    detailVersion++;
    detailRequest?.abort();
  });
  const editor = setupDashboardBuilder({
    root,
    grid,
    poller,
    chart,
    request,
    params,
    getData: () => data,
    handleDataError,
    onStart: () => {
      editing = true;
      const defaultStats = root.querySelector('[data-dashboard-default-stats]');
      if (defaultStats) defaultStats.hidden = true;
    },
    onFinish: () => {
      editing = false;
      widgetSignature = null;
      if (data) renderWidgets();
    },
  });
  finishEditing = editor.close;
  return {
    poller,
    setCompany(company, scope = {}) {
      poller.pause();
      contextVersion++;
      csvRequest?.abort();
      csvBusy = false;
      root.dataset.company = company;
      root.dataset.scopeVisit = scope.visit ?? '';
      root.dataset.scopeStatus = scope.visit_status ?? '';
      data = null;
      cross = null;
      exportState = null;
      exporting = false;
      submittingExport = false;
      confirmedExport = null;
      activeFilters = {};
      filters.reset();
      resetSelection();
      participantRows.clear();
      tbody.replaceChildren();
      detailRequest?.abort();
      detailVersion++;
      detail.close();
      confirm.close();
      root.querySelector('[data-hrd-cross-clear]').hidden = true;
      root.querySelector('[data-hrd-export-state]').hidden = true;
      root.querySelector('[data-hrd-download]').hidden = true;
      exportButton.disabled = true;
      if (company) poller.resume();
    },
  };
}

export function setupDashboardBuilder({
  root,
  grid,
  poller,
  chart,
  request,
  params = () => new URLSearchParams(),
  getData,
  handleDataError,
  saveDashboard,
  onLiveData = () => {},
  onStart = () => {},
  onFinish = () => {},
}) {
  const doc = root.ownerDocument;
  const builder = root.querySelector('[data-hrd-builder]');
  const el = (tag, text = '', className = '') => {
    const node = doc.createElement(tag);
    node.textContent = text;
    node.className = className;
    return node;
  };
  const button = (text, action, className = 'hrd-button') => {
    const node = el('button', text, className);
    node.type = 'button';
    node.addEventListener('click', action);
    return node;
  };
  let data;
  let draft = [];
  let editing = false;
  let finishEditing = () => {};
  let startEditing = () => {};
  let isDirty = () => false;
  let refreshDraft = () => {};
  let confirmAction = () => {};
  if (builder) {
    const list = grid;
    const catalog = root.querySelector('[data-hrd-catalog]');
    const catalogList = root.querySelector('[data-hrd-catalog-list]');
    const search = root.querySelector('[data-hrd-catalog-search]');
    const category = root.querySelector('[data-hrd-catalog-category]');
    const settings = root.querySelector('[data-hrd-widget-settings]');
    const inlineSettings = settings.tagName === 'ASIDE';
    const add = root.querySelector('[data-hrd-widget-form]');
    const confirmation = root.querySelector('[data-hrd-builder-confirm]');
    const status = builder.querySelector('[data-hrd-builder-status]');
    let widgetKeys = [];
    let nextKey = 0;
    let history = [];
    let historyIndex = -1;
    let gestureSnapshot;
    const undoButton = builder.querySelector('[data-dashboard-undo]');
    const redoButton = builder.querySelector('[data-dashboard-redo]');
    let sources = [];
    let previews = [];
    let previewTimer;
    let previewRequest;
    let previewRunning = false;
    let previewRequested = false;
    let previewVersion = 0;
    let previewCoolingDown = false;
    let saving = false;
    let selectedWidget;
    let originalDraft;
    let useDefault = false;
    let confirmedAction;
    let suspended = false;
    const fields = Object.fromEntries(
      [
        'source',
        'dimension',
        'chart',
        'metric',
        'title',
        'width',
        'height',
        'filter_source',
        'filter_value',
      ].map((name) => [name, add.elements.namedItem(name)])
    );
    const dirty = () => useDefault || JSON.stringify(draft) !== originalDraft;
    const snapshot = () => ({ widgets: structuredClone(draft), keys: [...widgetKeys], useDefault });
    const updateHistoryControls = () => {
      if (undoButton) undoButton.disabled = saving || historyIndex <= 0;
      if (redoButton) redoButton.disabled = saving || historyIndex >= history.length - 1;
    };
    const checkpoint = () => {
      const current = snapshot();
      if (JSON.stringify(history[historyIndex]) !== JSON.stringify(current)) {
        history = history.slice(0, historyIndex + 1);
        history.push(current);
        if (history.length > 50) history.shift();
        historyIndex = history.length - 1;
      }
      updateHistoryControls();
    };
    isDirty = dirty;
    const hideSettings = () => {
      if (inlineSettings) settings.hidden = true;
      else settings.close();
    };
    const ask = (title, text, action) => {
      confirmedAction = action;
      confirmation.querySelector('h2').textContent = title;
      root.querySelector('[data-hrd-builder-confirm-text]').textContent = text;
      confirmation.showModal();
    };
    confirmAction = ask;
    const sourceOptions = () =>
      sources.map((source) => [
        source.id,
        `${source.group} · ${source.title}${source.unit ? ` (${source.unit})` : ''}${source.reference ? ` · ${source.reference}` : ''}`,
      ]);
    const dimensionOptions = () => [
      ['', 'Nilai sumber itu sendiri'],
      ...sources
        .filter((source) => ['choice', 'laboratory'].includes(source.kind))
        .map((source) => [source.id, source.title]),
    ];
    const chartOptions = [
      ['bar', 'Bar'],
      ['stacked', 'Stacked bar'],
      ['pie', 'Pie'],
      ['donut', 'Donut'],
      ['table', 'Tabel'],
      ['indicator', 'Indikator'],
      ['number', 'Kartu angka'],
    ];
    const metricOptions = [
      ['count', 'Jumlah'],
      ['percent', 'Persentase'],
      ['average', 'Rata-rata'],
      ['min', 'Minimum'],
      ['max', 'Maksimum'],
    ];
    const fillOptions = (select, entries, value) => {
      select.replaceChildren(
        ...entries.map(([key, name]) => Object.assign(el('option', name), { value: String(key) }))
      );
      select.value = String(value ?? '');
    };
    const normalizeOptions = (sourceId, selects, widget) => {
      const source = sources.find((source) => source.id === sourceId);
      const kpi = source?.kind === 'kpi';
      const fixed = kpi || source?.kind === 'operation_table';
      const fixedChart = kpi ? 'number' : 'table';
      const advanced = add.querySelector('[data-dashboard-advanced]');
      if (advanced) advanced.hidden = fixed;
      [...selects.metric.options].forEach((option) => {
        option.disabled =
          (fixed && option.value !== 'count') ||
          (['average', 'min', 'max'].includes(option.value) && source?.kind !== 'number');
      });
      if (selects.metric.selectedOptions[0]?.disabled) {
        selects.metric.value = 'count';
        if (widget) widget.metric = 'count';
      }
      [...selects.chart.options].forEach((option) => {
        option.disabled =
          (fixed && option.value !== fixedChart) ||
          (option.value === 'indicator' && source?.kind !== 'laboratory') ||
          (['average', 'min', 'max'].includes(selects.metric.value) &&
            ['pie', 'donut', 'stacked'].includes(option.value));
      });
      if (selects.chart.selectedOptions[0]?.disabled) {
        selects.chart.value = fixed ? fixedChart : 'bar';
        if (widget) widget.chart = selects.chart.value;
      }
      selects.dimension.disabled = fixed || source?.kind === 'ranking';
      if (selects.dimension.disabled) {
        selects.dimension.value = '';
        if (widget) widget.dimension = '';
      }
      if (selects.filter_source) {
        selects.filter_source.disabled = fixed;
        selects.filter_value.disabled = fixed;
        if (fixed) {
          selects.filter_source.value = '';
          selects.filter_value.value = '';
        }
      }
    };
    const renderPreviews = () => {
      [...list.querySelectorAll('[data-hrd-edit-index]')].forEach((card, index) => {
        const target = card.querySelector('[data-builder-preview]');
        if (!target) return;
        const scroll = target.scrollTop;
        const widget = previews[index];
        const signature = JSON.stringify(widget);
        if (target.dataset.signature === signature) return;
        target.dataset.signature = signature;
        target.replaceChildren(
          widget
            ? chart(widget)
            : el('p', 'Pratinjau akan muncul di sini.', 'p-4 text-sm text-slate-500')
        );
        target.scrollTop = scroll;
      });
    };
    const runPreview = async () => {
      if (!editing || saving || doc.hidden || suspended || previewCoolingDown) return;
      if (gestures.active()) {
        previewTimer = window.setTimeout(runPreview, 4000);
        return;
      }
      if (previewRunning) {
        previewRequested = true;
        return;
      }
      previewRunning = true;
      previewRequested = false;
      window.clearTimeout(previewTimer);
      previewRequest = new AbortController();
      let timedOut = false;
      const timeoutTimer = window.setTimeout(() => {
        timedOut = true;
        previewRequest?.abort();
      }, 30000);
      const version = previewVersion;
      status.textContent = 'Menyiapkan pratinjau…';
      try {
        const result = await request(`${root.dataset.previewUrl}?${params()}`, {
          method: 'POST',
          signal: previewRequest.signal,
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ widgets: useDefault ? [] : draft, use_default: useDefault }),
        });
        if (version === previewVersion && editing) {
          previews = result.widgets;
          onLiveData(result);
          if (useDefault && JSON.stringify(draft) !== JSON.stringify(result.layout)) {
            draft = result.layout.map((widget) => ({ ...widget }));
            widgetKeys = draft.map(() => `widget-${++nextKey}`);
            renderDraft();
            history[historyIndex] = snapshot();
            updateHistoryControls();
          } else renderPreviews();
          status.textContent = useDefault
            ? 'Tampilan default disiapkan. Simpan untuk menerapkannya ke akun Anda.'
            : 'Pratinjau diperbarui · perubahan belum disimpan.';
        }
      } catch (exception) {
        if (exception.revoked) {
          handleDataError(exception);
          return;
        }
        if (exception.status === 429 && version === previewVersion) {
          previewCoolingDown = true;
          window.setTimeout(() => {
            previewCoolingDown = false;
            if (editing) schedulePreview();
          }, exception.retryAfter * 1000);
        }
        if ((timedOut || exception.name !== 'AbortError') && version === previewVersion && editing)
          status.textContent = `${timedOut ? 'Permintaan terlalu lama.' : exception.message} Data terakhir tetap ditampilkan.`;
      } finally {
        window.clearTimeout(timeoutTimer);
        previewRunning = false;
        window.clearTimeout(previewTimer);
        if (
          editing &&
          !saving &&
          !doc.hidden &&
          !suspended &&
          !previewCoolingDown &&
          (previewRequested || saveDashboard)
        )
          previewTimer = window.setTimeout(
            runPreview,
            previewRequested ? (saveDashboard ? 300 : 900) : 4000
          );
      }
    };
    const schedulePreview = () => {
      previewVersion++;
      window.clearTimeout(previewTimer);
      previewRequest?.abort();
      previewRequested = true;
      if (!previewRunning && !previewCoolingDown)
        previewTimer = window.setTimeout(runPreview, saveDashboard ? 300 : 900);
    };
    refreshDraft = schedulePreview;
    const renderDraft = (animate = root.dataset.keyboard !== 'true') => {
      const before = captureDashboardRects(list);
      list.replaceChildren(
        ...draft.map((widget, index) => {
          const card = el('div', '', 'hrd-widget hrd-builder-card');
          card.dataset.width = widget.width;
          card.dataset.height = widget.height;
          card.dataset.kind = sources.find((source) => source.id === widget.source)?.kind ?? 'text';
          card.dataset.hrdEditIndex = index;
          card.dataset.dashboardKey = widgetKeys[index];
          card.tabIndex = 0;
          card.setAttribute('role', 'group');
          card.setAttribute('aria-roledescription', 'Kartu dashboard yang dapat dipindahkan');
          card.setAttribute('aria-label', widget.title);
          card.setAttribute('aria-describedby', 'dashboard-gesture-help');
          card.dataset.selected = String(
            selectedWidget === index && (!inlineSettings || !settings.hidden)
          );
          const changed = () => {
            hideSettings();
            selectedWidget = undefined;
            useDefault = false;
            renderDraft();
            checkpoint();
            schedulePreview();
          };
          const heading = el('div', '', 'hrd-widget-heading');
          const title = el('div', '', 'min-w-0');
          title.append(
            el('h3', widget.title, 'hrd-widget-title'),
            el(
              'p',
              `${card.dataset.kind === 'kpi' ? 'KPI' : 'Analisis'} · ${widget.width}/3 lebar desktop`,
              'hrd-widget-subtitle'
            )
          );
          const configure = button('Atur', () => openSettings(Number(card.dataset.hrdEditIndex)));
          configure.dataset.hrdSettingsOpen = '';
          configure.setAttribute('aria-label', `Atur ${widget.title}`);
          heading.append(title, configure);
          if (inlineSettings)
            card.addEventListener('click', (event) => {
              if (!event.target.closest('button, a, input, select, [data-dashboard-resize]'))
                openSettings(Number(card.dataset.hrdEditIndex));
            });
          const actions = el('div', '', 'hrd-widget-actions');
          const preview = el('div');
          preview.dataset.builderPreview = '';
          preview.inert = true;
          preview.append(
            previews[index]
              ? chart({ ...previews[index], ...widget })
              : el('p', 'Pratinjau akan muncul di sini.', 'p-4 text-sm text-slate-500')
          );
          actions.append(
            button('Hapus', () => {
              if (gestures.active()) return;
              const position = Number(card.dataset.hrdEditIndex);
              draft.splice(position, 1);
              previews.splice(position, 1);
              widgetKeys.splice(position, 1);
              changed();
              list.children[Math.min(position, draft.length - 1)]
                ?.querySelector('[data-hrd-settings-open]')
                ?.focus({ preventScroll: true });
            })
          );
          card.append(heading, preview, actions);
          for (const [edge, label] of [
            ['e', 'lebar'],
            ['s', 'tinggi'],
            ['se', 'lebar dan tinggi'],
          ]) {
            const handle = el('span', '', 'hrd-resize-handle');
            handle.dataset.dashboardResize = edge;
            handle.tabIndex = 0;
            handle.setAttribute('role', 'button');
            handle.setAttribute('aria-label', `Tarik untuk mengubah ${label} ${widget.title}`);
            card.append(handle);
          }
          return card;
        })
      );
      const addCard = button(
        '＋ Tambah widget',
        () => {
          renderCatalog();
          catalog.showModal();
        },
        'hrd-add-card'
      );
      addCard.dataset.dashboardAddCard = '';
      list.append(addCard);
      animateDashboardLayout(list, before, { animate });
      status.textContent = `${draft.length} widget · ${dirty() ? 'Perubahan belum disimpan' : 'Belum ada perubahan'}`;
    };
    const openSettings = (index, reveal = true) => {
      selectedWidget = index;
      const widget = draft[index];
      fillOptions(fields.source, sourceOptions(), widget.source);
      if (!sources.some((source) => source.id === widget.source)) {
        fields.source.append(
          Object.assign(el('option', 'Sumber lama · tidak tersedia'), { value: widget.source })
        );
        fields.source.value = widget.source;
      }
      fillOptions(fields.dimension, dimensionOptions(), widget.dimension ?? '');
      fillOptions(
        fields.filter_source,
        [
          ['', 'Tanpa filter khusus'],
          ...sourceOptions().filter(
            ([id]) =>
              !['kpi', 'ranking', 'operation_table'].includes(
                sources.find((source) => source.id === id)?.kind
              )
          ),
        ],
        widget.filter_source ?? ''
      );
      fillOptions(fields.chart, chartOptions, widget.chart);
      fillOptions(fields.metric, metricOptions, widget.metric);
      for (const name of ['title', 'width', 'height', 'filter_value'])
        fields[name].value = widget[name] ?? '';
      normalizeOptions(widget.source, fields);
      if (inlineSettings) {
        if (reveal) settings.hidden = false;
        [...list.children].forEach((card, position) => {
          card.dataset.selected = String(position === index);
        });
        if (reveal && window.matchMedia?.('(max-width: 1279px)').matches)
          list.children[index]?.scrollIntoView?.({ block: 'start' });
      } else if (reveal) settings.showModal();
    };
    const applySnapshot = (state, render = true) => {
      const selectedKey = widgetKeys[selectedWidget];
      const oldPreviews = new Map(widgetKeys.map((key, index) => [key, previews[index]]));
      draft = structuredClone(state.widgets);
      widgetKeys = [...state.keys];
      useDefault = state.useDefault;
      previews = widgetKeys.map((key) => oldPreviews.get(key) ?? null);
      selectedWidget = selectedKey === undefined ? undefined : widgetKeys.indexOf(selectedKey);
      if (selectedWidget === -1) selectedWidget = undefined;
      if (render) {
        if (selectedWidget === undefined) hideSettings();
        renderDraft();
      } else
        [...list.querySelectorAll('[data-hrd-edit-index]')].forEach((card, index) => {
          card.dataset.width = draft[index].width;
          card.dataset.height = draft[index].height;
        });
      if (selectedWidget !== undefined) openSettings(selectedWidget, false);
      updateHistoryControls();
    };
    const gestures = setupDashboardGestures({
      list,
      canStart: () => editing && !saving,
      getWidget: (index) => draft[index],
      onSelect: (index) => openSettings(index, false),
      onStart: () => {
        gestureSnapshot = snapshot();
        previewVersion++;
        previewRequest?.abort();
        window.clearTimeout(previewTimer);
        if (inlineSettings && window.matchMedia?.('(max-width: 1279px)').matches)
          settings.hidden = true;
      },
      onReorder: (from, to) => {
        const key = widgetKeys[selectedWidget];
        draft = moveHrdWidget(draft, from, to);
        previews = moveHrdWidget(previews, from, to);
        widgetKeys = moveHrdWidget(widgetKeys, from, to);
        selectedWidget = widgetKeys.indexOf(key);
        useDefault = false;
        status.textContent = `Posisi ${selectedWidget + 1} dari ${draft.length} · lepaskan untuk menempatkan kartu.`;
      },
      onResize: (index, width, height) => {
        draft[index].width = width;
        draft[index].height = height;
        useDefault = false;
        fields.width.value = String(width);
        fields.height.value = String(height);
        status.textContent = `${width}/3 lebar · tinggi ${height} · perubahan belum disimpan.`;
      },
      onCommit: () => {
        checkpoint();
        renderPreviews();
        schedulePreview();
        status.textContent = `${draft.length} widget · Perubahan belum disimpan`;
      },
      onCancel: () => {
        applySnapshot(gestureSnapshot, false);
        renderPreviews();
        schedulePreview();
      },
    });
    const travelHistory = (direction) => {
      if (!editing || saving || gestures.active()) return;
      const next = historyIndex + direction;
      if (next < 0 || next >= history.length) return;
      historyIndex = next;
      applySnapshot(history[next]);
      schedulePreview();
    };
    undoButton?.addEventListener('click', () => travelHistory(-1));
    redoButton?.addEventListener('click', () => travelHistory(1));
    root.addEventListener('keydown', (event) => {
      if (
        !editing ||
        !(event.ctrlKey || event.metaKey) ||
        event.target.closest('input, select, textarea, [contenteditable="true"]')
      )
        return;
      if (['z', 'y'].includes(event.key.toLowerCase())) {
        event.preventDefault();
        travelHistory(event.key.toLowerCase() === 'y' || event.shiftKey ? 1 : -1);
      }
    });
    const renderCatalog = () => {
      const matching = filterHrdCatalog(sources, search.value, category.value);
      catalogList.replaceChildren();
      root.querySelector('[data-hrd-catalog-count]').textContent =
        `${matching.length} widget tersedia · ${draft.length} di dashboard`;
      matching.forEach((source) => {
        const card = el('article', '', 'hrd-catalog-card');
        const info = el('div', '', 'min-w-0 flex-1');
        info.append(
          el('h3', source.title, 'break-words text-sm font-semibold text-slate-800'),
          el(
            'p',
            `${source.group}${source.unit ? ` · ${source.unit}` : ''}`,
            'mt-1 break-words text-xs text-slate-500'
          )
        );
        const count = draft.filter((widget) => widget.source === source.id).length;
        if (count)
          info.append(el('p', `${count} ditambahkan`, 'mt-1 text-xs font-medium text-emerald-700'));
        const addButton = button(
          '＋',
          () => {
            if (saving || gestures.active() || draft.length >= 200) return;
            draft.push(createHrdWidget(source));
            widgetKeys.push(`widget-${++nextKey}`);
            previews.push(null);
            useDefault = false;
            renderDraft();
            checkpoint();
            schedulePreview();
            renderCatalog();
            [...catalogList.children]
              .find((entry) => entry.dataset.source === source.id)
              ?.querySelector('button')
              ?.focus({ preventScroll: true });
          },
          'hrd-catalog-add'
        );
        addButton.setAttribute('aria-label', `Tambah ${source.title}`);
        addButton.disabled = saving || draft.length >= 200;
        const icon = el(
          'span',
          source.kind === 'kpi' ? '#' : source.kind === 'text' ? '≡' : '▥',
          'hrd-catalog-icon'
        );
        icon.setAttribute('aria-hidden', 'true');
        card.dataset.source = source.id;
        card.append(icon, info, addButton);
        catalogList.append(card);
      });
      if (!matching.length)
        catalogList.append(
          el(
            'p',
            'Tidak ada widget yang cocok. Coba kata kunci atau kategori lain.',
            'py-10 text-center text-sm leading-6 text-slate-500'
          )
        );
    };
    startEditing = (empty = false) => {
      data = getData();
      if (!data || editing) return;
      editing = true;
      onStart();
      poller.pause();
      draft = (empty ? [] : data.layout).map((widget) => ({
        ...widget,
        dimension: widget.dimension ?? '',
        filter_source: widget.filter_source ?? '',
        filter_value: widget.filter_value ?? '',
      }));
      previews = empty ? [] : [...data.widgets];
      widgetKeys = draft.map(() => `widget-${++nextKey}`);
      sources = data.builder_sources ?? data.sources;
      originalDraft = empty ? null : JSON.stringify(draft);
      selectedWidget = undefined;
      useDefault = false;
      history = [];
      historyIndex = -1;
      checkpoint();
      root.dataset.editing = 'true';
      builder.hidden = false;
      root.querySelector('[data-hrd-builder-open]').hidden = true;
      root
        .querySelectorAll(
          '[data-hrd-filters] input, [data-hrd-filters] select, [data-hrd-filters] button, [data-hrd-company]'
        )
        .forEach((node) => {
          node.disabled = true;
        });
      fillOptions(
        category,
        [
          ['', 'Semua kategori'],
          ...[...new Set(sources.map((source) => source.group))].map((group) => [group, group]),
        ],
        ''
      );
      search.value = '';
      renderCatalog();
      renderDraft();
      if (saveDashboard) previewTimer = window.setTimeout(runPreview, 4000);
      builder.scrollIntoView?.({ block: 'nearest' });
    };
    root.querySelector('[data-hrd-builder-open]').addEventListener('click', () => startEditing());
    ['source', 'metric'].forEach((field) =>
      add.elements
        .namedItem(field)
        .addEventListener('change', () => normalizeOptions(fields.source.value, fields))
    );
    builder.querySelector('[data-hrd-builder-close]').addEventListener('click', () => {
      if (saving) return;
      if (dirty())
        ask(
          'Batalkan perubahan?',
          'Perubahan dashboard yang belum disimpan akan dibuang. Dashboard tersimpan Anda tetap digunakan.',
          () => finishEditing()
        );
      else finishEditing();
    });
    finishEditing = (resume = true) => {
      gestures.cancel();
      editing = false;
      previewVersion++;
      previewRequest?.abort();
      window.clearTimeout(previewTimer);
      hideSettings();
      catalog.close();
      builder.hidden = true;
      root.dataset.editing = 'false';
      root.querySelector('[data-hrd-builder-open]').hidden = false;
      root
        .querySelectorAll(
          '[data-hrd-filters] input, [data-hrd-filters] select, [data-hrd-filters] button, [data-hrd-company]'
        )
        .forEach((node) => {
          node.disabled = false;
        });
      grid.replaceChildren();
      onFinish();
      root.querySelector('[data-hrd-builder-open]').focus({ preventScroll: true });
      if (resume) poller.resume();
    };
    const applySettings = (close = false) => {
      if (saving || gestures.active() || !draft[selectedWidget]) return;
      const previous = draft[selectedWidget];
      const next = Object.fromEntries(
        Object.entries(fields).map(([key, field]) => [
          key,
          ['width', 'height'].includes(key) ? Number(field.value) : field.value,
        ])
      );
      if (
        ['source', 'metric', 'dimension', 'filter_source', 'filter_value'].some(
          (key) => (previous[key] ?? '') !== (next[key] ?? '')
        )
      )
        previews[selectedWidget] = null;
      draft[selectedWidget] = next;
      useDefault = false;
      if (close) hideSettings();
      renderDraft();
      checkpoint();
      if (add.checkValidity?.() !== false) schedulePreview();
      if (close)
        list.children[selectedWidget]
          ?.querySelector('[data-hrd-settings-open]')
          ?.focus({ preventScroll: true });
    };
    add.addEventListener('submit', (event) => {
      event.preventDefault();
      if (!add.reportValidity()) return;
      applySettings(true);
    });
    if (inlineSettings) {
      add.addEventListener('input', () => applySettings());
      add.addEventListener('change', () => applySettings());
    }
    builder.querySelector('[data-hrd-builder-default]').addEventListener('click', () => {
      ask(
        'Kembalikan tampilan awal?',
        'Susunan pribadi akan dikembalikan ke dashboard bawaan sesuai hak akses Anda. Perubahan baru disimpan setelah menekan Simpan dashboard.',
        () => {
          hideSettings();
          selectedWidget = undefined;
          useDefault = true;
          checkpoint();
          schedulePreview();
          status.textContent = 'Menyiapkan tampilan awal…';
        }
      );
    });
    const persist = async (saveAs = false) => {
      if (saving || gestures.active()) return;
      if (inlineSettings && !settings.hidden && !add.reportValidity()) return;
      saving = true;
      previewRequest?.abort();
      window.clearTimeout(previewTimer);
      const controls = [
        ...builder.querySelectorAll('button, input, select'),
        ...grid.querySelectorAll('button'),
        ...settings.querySelectorAll('button, input, select'),
      ];
      controls.forEach((control) => {
        control.disabled = true;
      });
      status.textContent = 'Menyimpan…';
      let saveError;
      try {
        const result = saveDashboard
          ? await saveDashboard({ widgets: draft, use_default: useDefault }, saveAs)
          : await request(
              `${root.dataset.layoutUrl}${root.dataset.company ? '?company=' + encodeURIComponent(root.dataset.company) : ''}`,
              {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ widgets: useDefault ? [] : draft, use_default: useDefault }),
              }
            );
        if (saveDashboard && result === false) return;
        finishEditing();
        const layoutNote = root.querySelector('[data-hrd-layout-note]');
        if (layoutNote) layoutNote.textContent = 'Dashboard pribadi berhasil disimpan.';
      } catch (exception) {
        if (exception.revoked) handleDataError(exception);
        saveError = exception.message;
      } finally {
        saving = false;
        if (editing) {
          renderDraft();
          status.textContent = saveError
            ? `${saveError} Perubahan belum disimpan; Anda dapat mencoba lagi.`
            : 'Perubahan belum disimpan.';
        }
        controls.forEach((control) => {
          control.disabled = false;
        });
        updateHistoryControls();
        if (editing && saveDashboard) previewTimer = window.setTimeout(runPreview, 4000);
      }
    };
    builder.querySelector('[data-hrd-builder-save]').addEventListener('click', () => persist());
    root.querySelector('[data-dashboard-save-as]')?.addEventListener('click', () => persist(true));
    root.querySelector('[data-hrd-catalog-open]').addEventListener('click', () => {
      renderCatalog();
      catalog.showModal();
    });
    ['[data-hrd-catalog-close]', '[data-hrd-catalog-done]'].forEach((selector) =>
      root.querySelector(selector).addEventListener('click', () => catalog.close())
    );
    search.addEventListener('input', renderCatalog);
    category.addEventListener('change', renderCatalog);
    root.querySelector('[data-hrd-settings-close]').addEventListener('click', hideSettings);
    root
      .querySelector('[data-hrd-builder-confirm-cancel]')
      .addEventListener('click', () => confirmation.close());
    root.querySelector('[data-hrd-builder-confirm-accept]').addEventListener('click', () => {
      const action = confirmedAction;
      confirmedAction = undefined;
      confirmation.close();
      action?.();
    });
    [catalog, ...(!inlineSettings ? [settings] : []), confirmation].forEach((dialog) => {
      dialog.addEventListener('click', (event) => {
        if (event.target !== dialog) return;
        const rect = dialog.getBoundingClientRect();
        if (
          event.clientX < rect.left ||
          event.clientX > rect.right ||
          event.clientY < rect.top ||
          event.clientY > rect.bottom
        )
          dialog.close();
      });
    });
    catalog.addEventListener('close', () =>
      root.querySelector('[data-hrd-catalog-open]').focus({ preventScroll: true })
    );
    settings.addEventListener('close', () =>
      list.children[selectedWidget]
        ?.querySelector('[data-hrd-settings-open]')
        ?.focus({ preventScroll: true })
    );
    window.addEventListener('beforeunload', (event) => {
      if (editing && dirty()) {
        event.preventDefault();
        event.returnValue = '';
      }
    });
    doc.addEventListener('visibilitychange', () => {
      if (!editing || !saveDashboard) return;
      if (doc.hidden) {
        previewVersion++;
        previewRequest?.abort();
        window.clearTimeout(previewTimer);
      } else schedulePreview();
    });
    window.addEventListener('pagehide', () => {
      suspended = true;
      previewVersion++;
      previewRequest?.abort();
      window.clearTimeout(previewTimer);
    });
    window.addEventListener('pageshow', () => {
      suspended = false;
      if (editing && saveDashboard) schedulePreview();
    });
  }
  window.addEventListener('pagehide', () => poller.pause());
  root.addEventListener('keydown', () => {
    root.dataset.keyboard = 'true';
  });
  root.addEventListener('pointerdown', () => {
    root.dataset.keyboard = 'false';
  });
  window.addEventListener('pageshow', (event) => {
    if (event.persisted && !editing) poller.resume();
  });
  return {
    poller,
    close: (resume = true) => finishEditing(resume),
    start: startEditing,
    dirty: () => editing && isDirty(),
    refresh: () => {
      if (editing) refreshDraft();
    },
    confirm: (...args) => confirmAction(...args),
  };
}

if (typeof document !== 'undefined') {
  const root = document.querySelector('[data-hrd-dashboard]');
  if (root) setupHrdDashboard(root);
}
