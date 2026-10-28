import assert from 'node:assert/strict';
import { test } from 'node:test';
import { setupPackageBuilder } from '../../resources/js/package-builder.js';

class Node {
  constructor(key, parent) {
    this.key = key;
    this.parent = parent;
    this.children = [];
    this.events = {};
    this.dataset = {};
    this.value = '';
    this.checked = false;
    this.disabled = false;
    this.hidden = false;
    this.classList = { toggle() {} };
    parent?.children.push(this);
  }
  querySelectorAll(selector) {
    const key = selector === 'input[type="checkbox"]' ? 'checkbox' : selector.slice(1, -1);
    return this.children.flatMap((child) => [
      ...(child.key === key ? [child] : []),
      ...child.querySelectorAll(selector),
    ]);
  }
  querySelector(selector) {
    return this.querySelectorAll(selector)[0] ?? null;
  }
  closest(selector) {
    return this.key === selector.slice(1, -1) ? this : this.parent?.closest(selector);
  }
  contains(node) {
    return node === this || this.children.some((child) => child.contains(node));
  }
  addEventListener(name, handler) {
    (this.events[name] ??= []).push(handler);
  }
  emit(name, event = {}) {
    this.events[name]?.forEach((handler) => handler({ preventDefault() {}, ...event }));
  }
  focus() {
    this.focused = true;
  }
}

function harness() {
  const root = new Node('root');
  const control = (name) => new Node(`data-package-${name}`, root);
  const search = control('search');
  const clear = control('search-clear');
  const status = control('search-status');
  const empty = control('search-empty');
  const full = control('full');
  const group = (parent = root) => {
    const node = new Node('data-package-group', parent);
    node.toggle = new Node('data-package-toggle', node);
    return node;
  };
  const lab = group();
  const hematology = group(lab);
  const hormones = group(lab);
  const physical = group();
  const item = (parent, text, value) => {
    const node = new Node('data-package-search-item', parent);
    node.dataset.packageSearchText = text;
    node.input = new Node('checkbox', node);
    node.input.value = value;
    return node;
  };
  const hemoglobin = item(hematology, 'Laboratorium Hématologi Hemoglobin g/dL', 'hemoglobin');
  const tsh = item(hormones, 'Laboratorium Hormon TSH', 'tsh');
  const bmi = item(physical, 'Hasil Pemeriksaan Fisik BMI', 'bmi');
  setupPackageBuilder(root);
  const find = (value) => {
    search.value = value;
    search.emit('input');
  };
  return {
    root,
    search,
    clear,
    status,
    empty,
    full,
    lab,
    hematology,
    hormones,
    physical,
    hemoglobin,
    tsh,
    bmi,
    find,
  };
}

test('typing instantly filters item, category and subcategory text and hides empty nested groups', () => {
  const h = harness();
  h.find('  HEMATOLOGI   g/dl ');
  assert.equal(h.hemoglobin.hidden, false);
  assert.equal(h.tsh.hidden, true);
  assert.equal(h.bmi.hidden, true);
  assert.equal(h.lab.hidden, false);
  assert.equal(h.hematology.hidden, false);
  assert.equal(h.hormones.hidden, true);
  assert.equal(h.physical.hidden, true);
  assert.match(h.status.textContent, /1 dari 3/);
  h.find('laboratorium');
  assert.equal(h.tsh.hidden, false);
  assert.match(h.status.textContent, /2 dari 3/);
});

test('search never clears or disables checked items and clearing restores every item', () => {
  const h = harness();
  h.bmi.input.checked = true;
  h.find('hemoglobin');
  assert.equal(h.bmi.input.checked, true);
  assert.equal(h.bmi.input.disabled, false);
  assert.match(h.status.textContent, /1 dipilih/);
  h.clear.emit('click');
  assert.equal(h.bmi.hidden, false);
  assert.equal(h.bmi.input.checked, true);
  assert.equal(h.search.value, '');
  assert.equal(h.search.focused, true);
  assert.equal(h.clear.hidden, true);
});

test('bulk selection applies only to visible matches without changing hidden selections', () => {
  const h = harness();
  h.bmi.input.checked = true;
  h.find('hemoglobin');
  h.lab.toggle.emit('click');
  assert.equal(h.hemoglobin.input.checked, true);
  assert.equal(h.tsh.input.checked, false);
  assert.equal(h.bmi.input.checked, true);
  h.lab.toggle.emit('click');
  assert.equal(h.hemoglobin.input.checked, false);
  h.full.emit('click');
  assert.equal(h.hemoglobin.input.checked, true);
  assert.equal(h.tsh.input.checked, false);
  assert.match(h.full.textContent, /hasil pencarian/);
});

test('empty results show feedback while Escape restores the list and Enter cannot submit the form', () => {
  const h = harness();
  h.find('<script>not found</script>');
  assert.equal(h.empty.hidden, false);
  assert.equal(h.full.disabled, true);
  assert.equal(h.lab.hidden, true);
  let prevented = false;
  h.search.emit('keydown', {
    key: 'Enter',
    preventDefault: () => {
      prevented = true;
    },
  });
  assert.equal(prevented, true);
  h.search.emit('keydown', { key: 'Escape' });
  assert.equal(h.empty.hidden, true);
  assert.equal(h.full.disabled, false);
  assert.match(h.status.textContent, /3 dari 3/);
});

test('pages without a package search are unaffected', () => {
  assert.doesNotThrow(() => setupPackageBuilder(new Node('root')));
});
