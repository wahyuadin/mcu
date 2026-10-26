export function setupExaminationNavigation(document, window) {
  let root = document.querySelector('[data-examination-page]');
  if (!root) return;
  const notice = document.createElement('p');
  notice.setAttribute('role', 'status');
  notice.setAttribute('aria-live', 'polite');
  root.before(notice);
  let currentUrl = window.location.href;
  let currentIndex = 0;
  let restoringHistory = false;
  let sequence = 0;
  let draftVersion = 0;
  let controller;
  let submitting = false;
  const dirtyForms = new Set();
  window.history.replaceState({ ...window.history.state, examinationIndex: 0 }, '', currentUrl);
  const consent = () =>
    !dirtyForms.size ||
    window.confirm('Ada perubahan yang belum disimpan. Tinggalkan tab dan buang perubahan?');
  const restoreHistory = (targetIndex) => {
    restoringHistory = true;
    window.history.go(currentIndex - targetIndex);
  };
  const navigate = async (url, popIndex = null) => {
    if (submitting || !consent()) {
      if (popIndex !== null) restoreHistory(popIndex);
      return;
    }
    controller?.abort();
    controller = new AbortController();
    const request = controller;
    const id = ++sequence;
    const version = draftVersion;
    const timeout = window.setTimeout(() => request.abort(), 30000);
    notice.textContent = 'Memuat bagian pemeriksaan…';
    root.setAttribute('aria-busy', 'true');
    try {
      const response = await window.fetch(url, {
        signal: request.signal,
        cache: 'no-store',
        headers: { Accept: 'text/html' },
      });
      if (id !== sequence || version !== draftVersion) return;
      if (
        [401, 403, 404].includes(response.status) ||
        (response.redirected && new URL(response.url).pathname !== new URL(currentUrl).pathname)
      ) {
        document.dispatchEvent(new Event('mcu:exam-unmount'));
        root.replaceChildren();
        dirtyForms.clear();
        window.location.assign(response.redirected ? response.url : url.href);
        return;
      }
      if (!response.ok) throw new Error('request');
      const page = new DOMParser().parseFromString(await response.text(), 'text/html');
      if (id !== sequence || version !== draftVersion) return;
      const next = page.querySelector('[data-examination-page]');
      if (!next) throw new Error('page');
      document.dispatchEvent(new Event('mcu:exam-unmount'));
      root.replaceWith(next);
      root = next;
      dirtyForms.clear();
      const target = new URL(response.url || url.href);
      if (popIndex === null) {
        currentIndex++;
        window.history.pushState({ examinationIndex: currentIndex }, '', target);
      } else currentIndex = popIndex;
      currentUrl = target.href;
      document.title = page.title;
      const heading = document.querySelector('header h1');
      const nextHeading = page.querySelector('header h1');
      if (heading && nextHeading) heading.textContent = nextHeading.textContent;
      document.dispatchEvent(new Event('mcu:exam-mounted'));
      const selected = root.querySelector('[aria-current="page"]');
      selected?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
      const focusTarget = root.querySelector('h2');
      focusTarget?.setAttribute('tabindex', '-1');
      focusTarget?.focus({ preventScroll: true });
      if (target.hash)
        document
          .getElementById?.(decodeURIComponent(target.hash.slice(1)))
          ?.scrollIntoView({ block: 'start' });
      notice.textContent = 'Bagian pemeriksaan diperbarui.';
    } catch (error) {
      if (id !== sequence) return;
      notice.textContent =
        error.name === 'AbortError'
          ? 'Pemuatan dibatalkan atau terlalu lama. Klik tab untuk mencoba lagi.'
          : 'Bagian gagal dimuat. Isian tetap tersimpan di layar; klik tab untuk mencoba lagi.';
      if (popIndex !== null) restoreHistory(popIndex);
    } finally {
      window.clearTimeout(timeout);
      if (id === sequence) root.removeAttribute('aria-busy');
      if (
        id === sequence &&
        popIndex !== null &&
        currentUrl !== window.location.href &&
        !restoringHistory
      )
        restoreHistory(popIndex);
    }
  };
  document.addEventListener('click', (event) => {
    const link = event.target.closest('a');
    if (
      !link ||
      !root.contains(link) ||
      link.target ||
      link.hasAttribute('download') ||
      event.button !== 0 ||
      event.ctrlKey ||
      event.metaKey ||
      event.shiftKey ||
      event.altKey ||
      event.defaultPrevented
    )
      return;
    const url = new URL(link.href, currentUrl);
    const current = new URL(currentUrl);
    if (
      url.origin !== current.origin ||
      url.pathname !== current.pathname ||
      url.searchParams.has('download')
    )
      return;
    if (url.href === currentUrl) {
      event.preventDefault();
      return;
    }
    if (url.hash && url.search === current.search) return;
    event.preventDefault();
    navigate(url);
  });
  const trackChanges = (event) => {
    const form = event.target.closest('form');
    if (!form || !root.contains(form)) return;
    dirtyForms.add(form);
    draftVersion++;
    controller?.abort();
    notice.textContent = 'Ada perubahan yang belum disimpan.';
    const status = form.querySelector('[data-save-status]');
    if (status) status.textContent = 'Ada perubahan yang belum disimpan';
  };
  document.addEventListener('input', trackChanges);
  document.addEventListener('change', trackChanges);
  document.addEventListener('mcu:photo-reset', (event) => dirtyForms.delete(event.target));
  document.addEventListener('submit', (event) => {
    if (root.contains(event.target) && !event.defaultPrevented) {
      submitting = true;
      dirtyForms.clear();
      controller?.abort();
    }
  });
  window.addEventListener('beforeunload', (event) => {
    if (dirtyForms.size && !submitting) {
      event.preventDefault();
      event.returnValue = '';
    }
  });
  window.addEventListener('pageshow', () => {
    submitting = false;
  });
  window.addEventListener('popstate', (event) => {
    if (restoringHistory) {
      restoringHistory = false;
      return;
    }
    const index = event.state?.examinationIndex;
    if (typeof index !== 'number') {
      window.location.assign(window.location.href);
      return;
    }
    navigate(new URL(window.location.href), index);
  });
}

if (typeof document !== 'undefined') setupExaminationNavigation(document, window);
