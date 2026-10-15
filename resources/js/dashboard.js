import {
  createHrdChartRenderer,
  dashboardRateLimitError,
  setupDashboardBuilder,
  setupHrdDashboard,
  startHrdPolling,
} from './hrd-dashboard.js';

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
      el(
        'strong',
        widget.total == null ? '—' : Number(widget.total).toLocaleString('id-ID'),
        'hrd-kpi-value'
      ),
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
    const link = el(
      'a',
      `${visit.action_label ?? 'Buka'} →`,
      'inline-flex min-h-11 items-center font-semibold text-blue-800'
    );
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
  let activeId = new URL(window.location.href).searchParams.get('dashboard');
  let creating = false;
  let editingDashboard;
  let cross;
  let reportWorkspace;
  let librarySignature;
  let lastPreview;
  const cards = new Map();
  const selector = root.querySelector('[data-dashboard-select]');
  const context = root.querySelector('[data-dashboard-context]');
  const visitSelect = root.querySelector('[data-dashboard-visit]');
  const visitStatus = root.querySelector('[data-dashboard-visit-status]');
  const resetFilters = root.querySelector('[data-dashboard-filter-reset]');
  let visitOptions = visitSelect
    ? [...visitSelect.options]
        .filter((option) => option.value)
        .map((option) => ({
          id: option.value,
          company_id: option.dataset.companyId,
          status: option.dataset.status,
          label: option.textContent,
        }))
    : [];
  const libraryActions = root.querySelector('[data-dashboard-library-actions]');
  const reports = root.querySelector('[data-dashboard-reports]');
  const reportRoot = root.querySelector('[data-dashboard-report-workspace]');
  const nameDialog = root.querySelector('[data-dashboard-name-dialog]');
  const nameForm = root.querySelector('[data-dashboard-name-form]');
  const params = () => {
    const query = new URLSearchParams();
    if (root.dataset.company) query.set('company', root.dataset.company);
    if (root.dataset.visit) query.set('visit', root.dataset.visit);
    if (root.dataset.visitStatus) query.set('visit_status', root.dataset.visitStatus);
    if (activeId) query.set('dashboard', activeId);
    if (cross) {
      query.set('cross_source', cross.source);
      query.set('cross_value', cross.value);
    }
    return query;
  };
  const writeUrl = () => {
    const url = new URL(window.location.href);
    root.dataset.company
      ? url.searchParams.set('company', root.dataset.company)
      : url.searchParams.delete('company');
    if (url.searchParams.has('dashboard')) url.searchParams.set('dashboard', activeId ?? 'default');
    for (const [key, value] of [
      ['visit', root.dataset.visit],
      ['visit_status', root.dataset.visitStatus],
    ]) {
      value ? url.searchParams.set(key, value) : url.searchParams.delete(key);
    }
    window.history.replaceState(null, '', url);
  };
  const request = async (url, options = {}) => {
    const response = await fetch(url, {
      credentials: 'same-origin',
      ...options,
      headers: {
        Accept: 'application/json',
        'X-CSRF-TOKEN': doc.querySelector('input[name="_token"]')?.value ?? root.dataset.csrf,
        ...options.headers,
      },
    });
    if ([401, 403, 419].includes(response.status))
      throw Object.assign(new Error('Akses atau sesi berakhir. Silakan masuk kembali.'), {
        revoked: true,
      });
    if (response.status === 429) throw dashboardRateLimitError(response);
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
  const { chart: analyticalChart } = createHrdChartRenderer(doc, (source, value) => {
    if (editing) return;
    cross = { source, value };
    const clear = root.querySelector('[data-dashboard-cross-clear]');
    clear.hidden = false;
    clear.textContent = `Filtrasi analisis: ${value} · Hapus ×`;
    poller.refresh();
  });
  const chart = (widget) =>
    widget.kind === 'operation_table' ||
    (widget.kind === 'kpi' && !widget.source.startsWith('stat:'))
      ? renderOperationalWidget(widget, doc)
      : analyticalChart(widget);
  const renderLibrary = () => {
    if (!selector || !data || editing) return;
    const defaultDashboard = data.default_dashboard ?? {
      id: 'default',
      name: 'Dashboard',
      version: 1,
    };
    const next = JSON.stringify([defaultDashboard, data.dashboards]);
    if (next !== librarySignature) {
      librarySignature = next;
      selector.replaceChildren(
        ...[defaultDashboard, ...data.dashboards].map((entry) => {
          const option = doc.createElement('option');
          option.value = entry.id;
          option.textContent = entry.name;
          return option;
        })
      );
    }
    selector.value = activeId ?? 'default';
    selector.disabled = false;
    root.querySelectorAll('[data-dashboard-new], [data-dashboard-duplicate]').forEach((node) => {
      node.disabled = false;
    });
    root.querySelector('[data-dashboard-rename]').hidden = false;
    root.querySelector('[data-dashboard-delete]').hidden = !data.dashboard;
  };
  const render = () => {
    if (!data || editing) return;
    defaults.hidden = data.personal_layout;
    grid.hidden = !data.personal_layout;
    root.querySelector('[data-hrd-layout-note]').textContent = data.personal_layout
      ? `${data.dashboard?.name ?? 'Dashboard pribadi'} · tersimpan untuk akun ini.`
      : `${data.default_dashboard?.name ?? 'Dashboard'} · personalisasi bersifat opsional.`;
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
    grid.querySelector('[data-dashboard-empty]')?.remove();
    const keys = new Set();
    data.widgets.forEach((widget, index) => {
      const key = `${index}:${widget.source}`;
      keys.add(key);
      let state = cards.get(key);
      if (!state) {
        const card = doc.createElement('article');
        card.className = 'hrd-widget';
        const heading = doc.createElement('div');
        heading.className = 'hrd-widget-heading';
        const title = doc.createElement('h3');
        title.className = 'hrd-widget-title';
        const subtitle = doc.createElement('p');
        subtitle.className = 'hrd-widget-subtitle';
        const text = doc.createElement('div');
        text.append(title, subtitle);
        heading.append(text);
        card.append(heading);
        state = { card, title, subtitle };
        cards.set(key, state);
      }
      state.card.dataset.width = widget.width;
      state.card.dataset.height = widget.height;
      state.card.dataset.kind = widget.kind;
      state.title.textContent = widget.title;
      state.subtitle.textContent = [
        widget.group,
        widget.dimension_title,
        widget.unit,
        widget.reference,
      ]
        .filter(Boolean)
        .join(' · ');
      const nextWidget = JSON.stringify(widget);
      if (state.signature !== nextWidget) {
        const scroll = state.body?.scrollTop ?? 0;
        const focused = state.card.contains(doc.activeElement)
          ? { ...doc.activeElement.dataset }
          : null;
        const body = chart(widget);
        state.body ? state.body.replaceWith(body) : state.card.append(body);
        state.body = body;
        state.signature = nextWidget;
        body.scrollTop = scroll;
        if (focused?.crossSource)
          [...body.querySelectorAll('[data-cross-source]')]
            .find(
              (node) =>
                node.dataset.crossSource === focused.crossSource &&
                node.dataset.crossValue === focused.crossValue
            )
            ?.focus({ preventScroll: true });
      }
      if (grid.children[index] !== state.card)
        grid.insertBefore(state.card, grid.children[index] ?? null);
    });
    cards.forEach((state, key) => {
      if (!keys.has(key)) {
        state.card.remove();
        cards.delete(key);
      }
    });
    if (!data.widgets.length) {
      const empty = doc.createElement('p');
      empty.className = 'hrd-empty text-slate-500';
      empty.dataset.dashboardEmpty = '';
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
      reportWorkspace?.setCompany('');
      reportWorkspace?.poller.stop();
      if (reports) reports.hidden = true;
      reportRoot?.querySelector('[data-hrd-detail-content]')?.replaceChildren();
      root
        .querySelectorAll(
          '[data-dashboard-select], [data-dashboard-context], [data-dashboard-library-actions] button'
        )
        .forEach((node) => {
          node.disabled = true;
        });
    }
  };
  const poller = startHrdPolling({
    document: doc,
    load: (signal) => request(`${root.dataset.dataUrl}?${params()}`, { signal }),
    apply: (result) => {
      data = result;
      if (result.visit_options) visitOptions = result.visit_options;
      syncVisitOptions();
      activeId = result.active_dashboard;
      error.hidden = true;
      opener.disabled = false;
      status.textContent = `Diperbarui ${new Date(result.updated_at).toLocaleTimeString('id-ID')} · otomatis setiap 4 detik`;
      render();
      renderLibrary();
    },
    error: handleError,
  });
  editor = setupDashboardBuilder({
    root,
    grid,
    poller,
    request,
    getData: () => data,
    chart,
    params,
    handleDataError: handleError,
    saveDashboard: async (payload, saveAs) => {
      [context, visitSelect, visitStatus, resetFilters].forEach((node) => {
        if (node) node.disabled = true;
      });
      try {
        let widgets = payload.widgets;
        if (payload.use_default) {
          const reset = await request(`${root.dataset.previewUrl}?${params()}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ widgets: [], use_default: true }),
          });
          widgets = reset.layout;
        }
        const target = editingDashboard;
        let result;
        if (saveAs || creating || !target) {
          result = await promptName(
            'Simpan sebagai dashboard baru',
            target ? `${target.name} · salinan` : '',
            (name) =>
              request(`${root.dataset.savedUrl}?${params()}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ name, widgets }),
              })
          );
          if (!result) return false;
        } else {
          result = await request(
            `${root.dataset.savedUrl}/${encodeURIComponent(target.id)}?${params()}`,
            {
              method: 'PATCH',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ widgets, version: target.version }),
            }
          );
        }
        activeId = result.dashboard.id;
        const saved = result.dashboard;
        const metadata = { id: saved.id, name: saved.name, version: saved.version };
        data = {
          ...data,
          active_dashboard: saved.id,
          dashboard: metadata,
          personal_layout: true,
          layout: saved.widgets,
          dashboards: [...data.dashboards.filter((entry) => entry.id !== saved.id), metadata],
          widgets: saved.widgets.map((config, index) => {
            const previous =
              lastPreview?.widgets[index]?.source === config.source
                ? lastPreview.widgets[index]
                : data.widgets.find((entry) => entry.source === config.source);
            const source = data.sources.find((entry) => entry.id === config.source);
            return {
              kind: source?.kind ?? 'text',
              group: source?.group ?? '',
              total: null,
              rows: [],
              ...previous,
              ...config,
            };
          }),
        };
        writeUrl();
        return true;
      } finally {
        [context, visitSelect, visitStatus, resetFilters].forEach((node) => {
          if (node) node.disabled = false;
        });
      }
    },
    onLiveData: (result) => {
      lastPreview = result;
      if (result.visit_options) {
        visitOptions = result.visit_options;
        syncVisitOptions();
      }
      status.textContent = `Mode edit · data diperbarui ${new Date(result.updated_at).toLocaleTimeString('id-ID')} · otomatis setiap 4 detik`;
    },
    onStart: () => {
      editing = true;
      lastPreview = null;
      editingDashboard = creating ? null : data.dashboard ? { ...data.dashboard } : null;
      const editName = root.querySelector('[data-dashboard-edit-name]');
      if (editName)
        editName.textContent = creating
          ? 'Dashboard baru'
          : `Edit: ${editingDashboard?.name ?? data.default_dashboard?.name ?? 'Dashboard'}`;
      if (selector) selector.disabled = true;
      if (libraryActions) libraryActions.hidden = true;
      defaults.hidden = true;
      grid.hidden = false;
    },
    onFinish: () => {
      editing = false;
      creating = false;
      if (libraryActions) libraryActions.hidden = false;
      signature = null;
      render();
      renderLibrary();
    },
  });
  const promptName = (title, initial, action) =>
    new Promise((resolve) => {
      nameDialog.querySelector('h2').textContent = title;
      nameForm.elements.namedItem('name').value = initial;
      const message = root.querySelector('[data-dashboard-name-error]');
      message.hidden = true;
      let busy = false;
      const cancel = () => {
        if (busy) return;
        cleanup();
        nameDialog.close();
        resolve(false);
      };
      const escape = (event) => {
        event.preventDefault();
        cancel();
      };
      const submit = async (event) => {
        event.preventDefault();
        if (busy || !nameForm.reportValidity()) return;
        busy = true;
        nameForm.querySelectorAll('button, input').forEach((node) => {
          node.disabled = true;
        });
        try {
          const result = await action(nameForm.elements.namedItem('name').value);
          cleanup();
          nameDialog.close();
          resolve(result);
        } catch (exception) {
          if (exception.revoked) {
            cleanup();
            nameDialog.close();
            handleError(exception);
            resolve(false);
          } else {
            message.textContent = exception.message;
            message.hidden = false;
          }
        } finally {
          busy = false;
          nameForm.querySelectorAll('button, input').forEach((node) => {
            node.disabled = false;
          });
        }
      };
      const cancelButton = root.querySelector('[data-dashboard-name-cancel]');
      const cleanup = () => {
        nameForm.removeEventListener('submit', submit);
        nameDialog.removeEventListener('cancel', escape);
        cancelButton.removeEventListener('click', cancel);
      };
      nameForm.addEventListener('submit', submit);
      nameDialog.addEventListener('cancel', escape);
      cancelButton.addEventListener('click', cancel);
      nameDialog.showModal();
      nameForm.elements.namedItem('name').focus();
    });
  const withLibraryAction = async (action) => {
    if (!data || editing) return;
    poller.pause();
    selector.disabled = true;
    libraryActions.querySelectorAll('button').forEach((node) => {
      node.disabled = true;
    });
    try {
      await action();
      writeUrl();
    } catch (exception) {
      handleError(exception);
    } finally {
      if (data) {
        libraryActions.querySelectorAll('button').forEach((node) => {
          node.disabled = false;
        });
        renderLibrary();
        poller.resume();
      }
    }
  };
  selector?.addEventListener('change', () =>
    withLibraryAction(async () => {
      const nextId = selector.value;
      await request(`${root.dataset.selectionUrl}?${params()}`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ dashboard: nextId }),
      });
      activeId = nextId;
      cross = null;
      root.querySelector('[data-dashboard-cross-clear]').hidden = true;
    })
  );
  root.querySelector('[data-dashboard-new]')?.addEventListener('click', () => {
    creating = true;
    editor.start(true);
  });
  root.querySelector('[data-dashboard-duplicate]')?.addEventListener('click', () =>
    withLibraryAction(async () => {
      const widgets = data.layout.map((widget) => ({ ...widget }));
      const result = await promptName(
        'Duplikat dashboard',
        `${data.dashboard?.name ?? 'Dashboard'} · salinan`,
        (name) =>
          request(`${root.dataset.savedUrl}?${params()}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ name, widgets }),
          })
      );
      if (result) activeId = result.dashboard.id;
    })
  );
  root.querySelector('[data-dashboard-rename]')?.addEventListener('click', () =>
    withLibraryAction(async () => {
      const target = {
        ...(data.dashboard ??
          data.default_dashboard ?? { id: 'default', name: 'Dashboard', version: 1 }),
      };
      await promptName('Ganti nama dashboard', target.name, (name) =>
        request(`${root.dataset.savedUrl}/${encodeURIComponent(target.id)}?${params()}`, {
          method: 'PATCH',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ name, version: target.version }),
        })
      );
    })
  );
  root.querySelector('[data-dashboard-delete]')?.addEventListener('click', () => {
    if (!data?.dashboard || editing) return;
    const target = { ...data.dashboard };
    editor.confirm(
      'Hapus dashboard?',
      `Hapus “${target.name}”? Dashboard lain dan data MCU tidak berubah.`,
      () =>
        withLibraryAction(async () => {
          await request(`${root.dataset.savedUrl}/${encodeURIComponent(target.id)}?${params()}`, {
            method: 'DELETE',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ version: target.version }),
          });
          activeId = 'default';
        })
    );
  });
  root.querySelector('[data-dashboard-cross-clear]')?.addEventListener('click', (event) => {
    cross = null;
    event.target.hidden = true;
    poller.refresh();
  });
  const syncVisitOptions = (manual = false) => {
    if (!visitSelect) return;
    const matching = visitOptions.filter(
      (entry) =>
        (!root.dataset.company || entry.company_id === root.dataset.company) &&
        (!root.dataset.visitStatus || entry.status === root.dataset.visitStatus)
    );
    if (manual && root.dataset.visit && !matching.some((entry) => entry.id === root.dataset.visit))
      root.dataset.visit = '';
    const entries = [{ id: '', label: 'Semua Kunjungan' }, ...matching];
    if (root.dataset.visit && !entries.some((entry) => entry.id === root.dataset.visit))
      entries.push({
        id: root.dataset.visit,
        label:
          visitSelect.selectedOptions[0]?.textContent ?? 'Kunjungan terpilih · di luar status ini',
      });
    const next = JSON.stringify(entries);
    if (visitSelect.dataset.signature !== next) {
      visitSelect.dataset.signature = next;
      visitSelect.replaceChildren(
        ...entries.map((entry) => {
          const option = doc.createElement('option');
          option.value = entry.id;
          option.textContent = entry.label ?? `${entry.number} · ${entry.period}`;
          return option;
        })
      );
    }
    visitSelect.value = root.dataset.visit ?? '';
  };
  const syncReports = () => {
    if (!reports) return;
    reports.hidden = !root.dataset.company;
    reportRoot.dataset.company = root.dataset.company;
    reportRoot.dataset.scopeVisit = root.dataset.visit ?? '';
    reportRoot.dataset.scopeStatus = root.dataset.visitStatus ?? '';
    reportWorkspace?.setCompany(root.dataset.company, {
      visit: root.dataset.visit ?? '',
      visit_status: root.dataset.visitStatus ?? '',
    });
    if (!reports.open) reportWorkspace?.poller.pause();
  };
  const applyScope = (manual = true) => {
    cross = null;
    root.querySelector('[data-dashboard-cross-clear]').hidden = true;
    syncVisitOptions(manual);
    writeUrl();
    syncReports();
    status.textContent = 'Memperbarui data sesuai filter…';
    if (editing) editor.refresh();
    else poller.refresh();
  };
  context?.addEventListener('change', () => {
    root.dataset.company = context.value;
    applyScope();
  });
  visitStatus?.addEventListener('change', () => {
    root.dataset.visitStatus = visitStatus.value;
    applyScope();
  });
  visitSelect?.addEventListener('change', () => {
    root.dataset.visit = visitSelect.value;
    applyScope(false);
  });
  resetFilters?.addEventListener('click', () => {
    if (context) {
      context.value = '';
      root.dataset.company = '';
    }
    root.dataset.visit = '';
    root.dataset.visitStatus = '';
    if (visitStatus) visitStatus.value = '';
    applyScope();
  });
  reports?.addEventListener('toggle', () => {
    if (reports.open && root.dataset.company) {
      reportRoot.dataset.scopeVisit = root.dataset.visit ?? '';
      reportRoot.dataset.scopeStatus = root.dataset.visitStatus ?? '';
      reportWorkspace ??= setupHrdDashboard(reportRoot);
      reportWorkspace.poller.resume();
    } else reportWorkspace?.poller.pause();
  });
  root.addEventListener('click', (event) => {
    if (
      event.target.closest('a')?.getAttribute('href') === '#hrd-participants' &&
      reports &&
      !reports.hidden
    )
      reports.open = true;
  });
  if (reports && window.location.hash === '#hrd-participants' && !reports.hidden)
    reports.open = true;
  return { poller, editor };
}

if (typeof document !== 'undefined') {
  const root = document.querySelector('[data-dashboard-workspace]');
  if (root) setupOperationalDashboard(root);
}
