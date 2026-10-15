import { setupDashboardBuilder, startHrdPolling } from './hrd-dashboard.js';

export function renderOperationalWidget(widget, doc = document) {
  const el = (tag, text = '', className = '') => {
    const node = doc.createElement(tag);
    node.textContent = text;
    node.className = className;
    return node;
  };
  const body = el('div', '', 'hrd-widget-body');
  if (widget.kind === 'kpi') {
    body.append(
      el('strong', Number(widget.total).toLocaleString('id-ID'), 'hrd-kpi-value'),
      el('p', 'Data terkini', 'text-xs text-slate-500')
    );
    return body;
  }
  const table = el('table', '', 'hrd-chart-table');
  const head = el('thead');
  const tr = el('tr');
  ['Nomor / periode', 'Perusahaan', 'Peserta', 'Aksi'].forEach((title) =>
    tr.append(el('th', title))
  );
  head.append(tr);
  table.append(head);
  const rows = el('tbody');
  widget.rows.forEach((visit) => {
    const row = el('tr');
    const number = el('td', visit.number);
    number.append(el('small', visit.period, 'mt-1 block text-xs text-slate-400'));
    const action = el('td');
    const link = el('a', 'Buka →', 'inline-flex min-h-11 items-center font-semibold text-blue-800');
    link.href = visit.url;
    action.append(link);
    row.append(number, el('td', visit.company), el('td', String(visit.participants)), action);
    rows.append(row);
  });
  if (!widget.rows.length) {
    const row = el('tr');
    const cell = el('td', 'Belum ada kunjungan.', 'py-6 text-center text-slate-500');
    cell.colSpan = 4;
    row.append(cell);
    rows.append(row);
  }
  table.append(rows);
  body.append(table);
  return body;
}

export function setupOperationalDashboard(root) {
  const doc = root.ownerDocument;
  const grid = root.querySelector('[data-dashboard-widgets]');
  const defaults = root.querySelector('[data-dashboard-default]');
  const error = root.querySelector('[data-dashboard-error]');
  const status = root.querySelector('[data-dashboard-status]');
  const opener = root.querySelector('[data-hrd-builder-open]');
  let data;
  let editing = false;
  let signature;
  let visitsSignature;
  let editor;
  const request = async (url, options = {}) => {
    const response = await fetch(url, {
      credentials: 'same-origin',
      ...options,
      headers: {
        Accept: 'application/json',
        'X-CSRF-TOKEN': root.dataset.csrf,
        ...options.headers,
      },
    });
    if ([401, 403, 419].includes(response.status))
      throw Object.assign(new Error('Akses atau sesi berakhir. Silakan masuk kembali.'), {
        revoked: true,
      });
    if (!response.headers.get('content-type')?.includes('application/json'))
      throw new Error('Respons server tidak valid. Coba kembali.');
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
  const render = () => {
    if (!data || editing) return;
    defaults.hidden = data.personal_layout;
    grid.hidden = !data.personal_layout;
    root.querySelector('[data-hrd-layout-note]').textContent = data.personal_layout
      ? 'Dashboard pribadi · tersimpan untuk akun ini.'
      : 'Dashboard bawaan · personalisasi bersifat opsional.';
    root.querySelectorAll('[data-dashboard-stat]').forEach((node) => {
      node.textContent = data.stats[node.dataset.dashboardStat].toLocaleString('id-ID');
    });
    const nextVisits = JSON.stringify(data.recent_visits);
    if (nextVisits !== visitsSignature) {
      visitsSignature = nextVisits;
      const tbody = root.querySelector('[data-dashboard-visits]');
      tbody.replaceChildren();
      data.recent_visits.forEach((visit) => {
        const row = doc.createElement('tr');
        row.className = 'hover:bg-slate-50/50';
        for (const [index, value] of [
          visit.number,
          visit.company,
          visit.period,
          `${visit.participants}${visit.quota > 0 ? '/' + visit.quota : ''}`,
        ].entries()) {
          const cell = doc.createElement('td');
          cell.className =
            index === 0
              ? 'px-6 py-4 font-bold text-[#00008B]'
              : index === 1
                ? 'px-6 py-4 font-medium text-slate-800'
                : 'px-6 py-4';
          cell.textContent = value;
          row.append(cell);
        }
        const cell = doc.createElement('td');
        cell.className = 'px-6 py-4 text-right';
        const link = doc.createElement('a');
        link.href = visit.url;
        link.textContent = visit.action_label;
        link.className =
          'rounded-lg bg-slate-100 px-3 py-2 text-xs font-semibold hover:bg-[#00008B] hover:text-white';
        cell.append(link);
        row.append(cell);
        tbody.append(row);
      });
      if (!data.recent_visits.length) {
        const row = doc.createElement('tr');
        const cell = doc.createElement('td');
        cell.colSpan = 5;
        cell.className = 'px-6 py-12 text-center text-slate-400';
        cell.textContent = 'Belum ada kunjungan.';
        row.append(cell);
        tbody.append(row);
      }
    }
    const next = JSON.stringify(data.widgets);
    if (next === signature) return;
    signature = next;
    grid.replaceChildren();
    data.widgets.forEach((widget) => {
      const card = doc.createElement('article');
      card.className = 'hrd-widget';
      card.dataset.width = widget.width;
      card.dataset.height = widget.height;
      card.dataset.kind = widget.kind;
      const heading = doc.createElement('div');
      heading.className = 'hrd-widget-heading';
      const title = doc.createElement('h3');
      title.className = 'hrd-widget-title';
      title.textContent = widget.title;
      heading.append(title);
      card.append(heading, renderOperationalWidget(widget, doc));
      grid.append(card);
    });
    if (!data.widgets.length) {
      const empty = doc.createElement('p');
      empty.className = 'hrd-empty text-slate-500';
      empty.textContent = 'Belum ada widget. Klik Atur dashboard untuk memilih dari katalog.';
      grid.append(empty);
    }
  };
  const handleError = (exception) => {
    error.hidden = false;
    error.textContent = exception.message;
    if (exception.revoked) {
      data = null;
      signature = null;
      defaults.replaceChildren();
      defaults.hidden = true;
      grid.replaceChildren();
      if (editing) editor?.close(false);
      root.querySelectorAll('dialog').forEach((dialog) => dialog.close());
      opener.disabled = true;
      poller.stop();
    }
  };
  const poller = startHrdPolling({
    document: doc,
    load: (signal) => request(root.dataset.dataUrl, { signal }),
    apply: (result) => {
      data = result;
      error.hidden = true;
      opener.disabled = false;
      status.textContent = `Diperbarui ${new Date(result.updated_at).toLocaleTimeString('id-ID')} · otomatis setiap 4 detik`;
      render();
    },
    error: handleError,
  });
  editor = setupDashboardBuilder({
    root,
    grid,
    poller,
    request,
    getData: () => data,
    chart: (widget) => renderOperationalWidget(widget, doc),
    handleDataError: handleError,
    onStart: () => {
      editing = true;
      defaults.hidden = true;
      grid.hidden = false;
    },
    onFinish: () => {
      editing = false;
      signature = null;
      render();
    },
  });
  root.querySelector('[data-dashboard-context]')?.addEventListener('change', (event) => {
    if (event.target.value) window.location.assign(event.target.value);
  });
  return { poller };
}

if (typeof document !== 'undefined') {
  const root = document.querySelector('[data-dashboard-workspace]');
  if (root) setupOperationalDashboard(root);
}
