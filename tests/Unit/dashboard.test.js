import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createHrdWidget } from '../../resources/js/hrd-dashboard.js';
import { renderOperationalWidget } from '../../resources/js/dashboard.js';

class Node {
  children = [];
  className = '';
  text = '';
  constructor(tag) {
    this.tagName = tag;
  }
  set textContent(value) {
    this.text = String(value);
    this.children = [];
  }
  get textContent() {
    return this.text + this.children.map((child) => child.textContent).join('');
  }
  append(...nodes) {
    this.children.push(...nodes);
  }
}
const doc = { createElement: (tag) => new Node(tag) };

test('operational catalog creates full-width table widgets with the compatible visualization', () => {
  const widget = createHrdWidget({
    id: 'kunjungan_terbaru',
    title: 'Kunjungan',
    kind: 'operation_table',
  });
  assert.equal(widget.chart, 'table');
  assert.equal(widget.width, 3);
  assert.equal(widget.metric, 'count');
});

test('operational KPI renders an actual zero and large counts without changing the value', () => {
  assert.match(renderOperationalWidget({ kind: 'kpi', total: 0 }, doc).textContent, /^0/);
  assert.match(renderOperationalWidget({ kind: 'kpi', total: 12345 }, doc).textContent, /12.345/);
});

test('operational table uses plain text for untrusted company names and keeps the authorized URL', () => {
  const visit = {
    number: 'MCU-01',
    company: '<img src=x onerror=bad()>',
    period: '2026',
    participants: 2,
    url: '/kunjungan-mcu/visit-a',
  };
  const body = renderOperationalWidget({ kind: 'operation_table', rows: [visit] }, doc);
  const row = body.children[0].children[1].children[0];
  assert.equal(row.children[1].textContent, visit.company);
  assert.equal(row.children[1].children.length, 0);
  assert.equal(row.children[3].children[0].href, '/kunjungan-mcu/visit-a');
});

test('operational table has an honest empty state when no visits exist', () => {
  const body = renderOperationalWidget({ kind: 'operation_table', rows: [] }, doc);
  assert.match(body.textContent, /Belum ada kunjungan/);
});
