const normalize = (value) =>
  value.normalize('NFKD').replace(/\p{M}/gu, '').toLocaleLowerCase('id').trim();
const setHidden = (node, hidden) => {
  node.hidden = hidden;
  node.classList.toggle('hidden', hidden);
};

export function setupPackageBuilder(root) {
  const search = root.querySelector('[data-package-search]');
  if (!search) return;
  const clear = root.querySelector('[data-package-search-clear]');
  const status = root.querySelector('[data-package-search-status]');
  const empty = root.querySelector('[data-package-search-empty]');
  const full = root.querySelector('[data-package-full]');
  const items = [...root.querySelectorAll('[data-package-search-item]')].map((node) => ({
    node,
    input: node.querySelector('input[type="checkbox"]'),
    text: normalize(node.dataset.packageSearchText ?? node.textContent),
  }));
  const groups = [...root.querySelectorAll('[data-package-group]')];
  const update = () => {
    const terms = normalize(search.value).split(/\s+/).filter(Boolean);
    let visible = 0;
    for (const item of items) {
      const matches = terms.every((term) => item.text.includes(term));
      setHidden(item.node, !matches);
      if (matches) visible++;
    }
    for (const group of groups) {
      setHidden(group, !items.some((item) => group.contains(item.node) && !item.node.hidden));
    }
    const selected = items.filter((item) => item.input.checked).length;
    status.textContent = `${visible} dari ${items.length} pemeriksaan ditampilkan · ${selected} dipilih`;
    setHidden(clear, terms.length === 0);
    setHidden(empty, visible > 0);
    full.textContent = terms.length ? 'Pilih semua hasil pencarian' : 'Pilih semua pemeriksaan';
    full.disabled = visible === 0;
  };
  search.addEventListener('input', update);
  search.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') event.preventDefault();
    if (event.key === 'Escape') {
      event.preventDefault();
      search.value = '';
      update();
    }
  });
  clear.addEventListener('click', () => {
    search.value = '';
    update();
    search.focus();
  });
  root.addEventListener('change', update);
  root.querySelectorAll('[data-package-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
      const group = button.closest('[data-package-group]');
      const matching = items.filter((item) => group.contains(item.node) && !item.node.hidden);
      const select = matching.some((item) => !item.input.checked);
      matching.forEach((item) => {
        item.input.checked = select;
      });
      update();
    });
  });
  full.addEventListener('click', () => {
    items
      .filter((item) => !item.node.hidden)
      .forEach((item) => {
        item.input.checked =
          normalize(search.value) !== '' || !['asma', 'ginjal'].includes(item.input.value);
      });
    update();
  });
  update();
}

if (typeof document !== 'undefined') {
  document.querySelectorAll('[data-package-builder]').forEach(setupPackageBuilder);
}
