import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../resources/js/planning-dates.js', import.meta.url), 'utf8');

function setup(startValue = '2026-10-10', endValue = '2026-10-24') {
    const listeners = new Map();
    const start = { value: startValue, addEventListener: (type, callback) => listeners.set(type, callback) };
    const end = { value: endValue };
    const form = { querySelector: (selector) => selector === '[name="tgl_mulai"]' ? start : end };
    runInNewContext(source, { document: { querySelector: () => form } });
    return { start, end, dispatch: (type) => listeners.get(type)() };
}

test('changing start date updates end date by fourteen calendar days across year boundary', () => {
    const app = setup();
    app.start.value = '2026-12-25';
    app.dispatch('input');
    assert.equal(app.end.value, '2027-01-08');
});

test('end date remains editable and is not overwritten on initial load', () => {
    const app = setup('2026-10-10', '2026-11-02');
    assert.equal(app.end.value, '2026-11-02');
    app.end.value = '2026-11-12';
    assert.equal(app.end.value, '2026-11-12');
    app.start.value = '2026-10-11';
    app.dispatch('change');
    assert.equal(app.end.value, '2026-10-25');
});

test('calculation handles leap days and empty start does not erase manual end', () => {
    const app = setup();
    app.start.value = '2028-02-20';
    app.dispatch('input');
    assert.equal(app.end.value, '2028-03-05');
    app.start.value = '';
    app.dispatch('input');
    assert.equal(app.end.value, '2028-03-05');
});

test('pages without a planning form do not fail', () => {
    assert.doesNotThrow(() => runInNewContext(source, { document: { querySelector: () => null } }));
});
