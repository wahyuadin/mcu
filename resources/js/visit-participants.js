const workspace = document.querySelector('[data-visit-workspace]');
if (workspace) {
  let sequence = 0;
  let controller;
  let timer;
  const notice = document.createElement('p');
  notice.setAttribute('role', 'status');
  workspace.prepend(notice);
  const formUrl = () => {
    const form = workspace.querySelector('[data-visit-search]');
    const url = new URL(form.action);
    for (const [key, value] of new FormData(form)) {
      if (value) url.searchParams.set(key, value);
    }
    return url;
  };
  const load = async (url, id = ++sequence, push = true) => {
    controller?.abort();
    controller = new AbortController();
    notice.textContent = 'Memuat peserta…';
    workspace.setAttribute('aria-busy', 'true');
    try {
      const response = await fetch(url, {
        signal: controller.signal,
        headers: { Accept: 'text/html' },
      });
      if (!response.ok || response.redirected) throw new Error('request failed');
      const page = new DOMParser().parseFromString(await response.text(), 'text/html');
      if (id !== sequence) return;
      if (!page.querySelector('[data-visit-participants]')) throw new Error('invalid page');
      const focused = document.activeElement?.name;
      const cursor = document.activeElement?.selectionStart;
      for (const selector of [
        '[data-visit-progress]',
        '[data-visit-cards]',
        '[data-visit-participants]',
      ]) {
        workspace.querySelector(selector).replaceWith(page.querySelector(selector));
      }
      if (focused === 'q') {
        const input = workspace.querySelector('[name="q"]');
        input.focus({ preventScroll: true });
        if (cursor !== null) input.setSelectionRange(cursor, cursor);
      }
      if (push) history.replaceState(null, '', url);
      notice.textContent = 'Daftar peserta diperbarui.';
    } catch (error) {
      if (id === sequence && error.name !== 'AbortError')
        notice.textContent =
          'Gagal memuat peserta. Ubah pencarian atau klik filter untuk mencoba lagi.';
    } finally {
      if (id === sequence) workspace.removeAttribute('aria-busy');
    }
  };
  const search = (delay) => {
    clearTimeout(timer);
    controller?.abort();
    const id = ++sequence;
    const url = formUrl();
    const exportUrl = new URL(url);
    exportUrl.searchParams.set('download', 'xlsx');
    workspace.querySelector('[data-visit-export]').href = exportUrl;
    timer = setTimeout(() => load(url, id), delay);
  };
  workspace.addEventListener('input', (event) => {
    if (event.target.matches('[name="q"]')) search(200);
  });
  workspace.addEventListener('submit', (event) => {
    if (event.target.matches('[data-visit-search]')) {
      event.preventDefault();
      search(0);
    }
  });
  workspace.addEventListener('click', (event) => {
    const anchor = event.target.closest('a');
    if (
      !anchor ||
      event.ctrlKey ||
      event.metaKey ||
      event.shiftKey ||
      event.altKey ||
      event.button !== 0
    )
      return;
    const url = new URL(anchor.href);
    if (
      anchor.matches('[data-visit-filter]') ||
      (anchor.closest('[data-visit-participants]') &&
        url.pathname === location.pathname &&
        !url.searchParams.has('download'))
    ) {
      event.preventDefault();
      clearTimeout(timer);
      if (anchor.matches('[data-visit-filter]')) {
        const query = workspace.querySelector('[name="q"]').value;
        if (query) url.searchParams.set('q', query);
      }
      load(url);
    }
  });
  window.addEventListener('popstate', () => load(new URL(location.href), ++sequence, false));
}
