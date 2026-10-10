import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../resources/js/package-selection.js', import.meta.url), 'utf8');

test('all master packages remain selectable before and after changing visits', () => {
    let change;
    const visit = { value: 'visit-a', addEventListener: (event, callback) => { change = callback; } };
    const shared = { value: 'shared', dataset: { kunjungans: '["visit-a","visit-b"]' } };
    const exclusive = { value: 'exclusive', dataset: { kunjungans: '["visit-a"]' } };
    const master = { value: 'master', dataset: { kunjungans: '[]' } };
    const packages = {
        options: [{ value: '' }, shared, exclusive, master], value: 'shared',
        get selectedOptions() { return this.options.filter((option) => option.value === this.value); },
    };
    runInNewContext(source, { document: { getElementById: (id) => id === 'patient-visit' ? visit : packages } });
    assert.equal(shared.disabled, false);
    assert.equal(exclusive.disabled, false);
    assert.equal(master.disabled, false);
    visit.value = 'visit-b';
    change();
    assert.equal(shared.disabled, false);
    assert.equal(exclusive.disabled, false);
    assert.equal(exclusive.hidden, false);
    assert.equal(packages.value, 'shared');
    visit.value = 'visit-c';
    change();
    assert.equal(packages.value, 'shared');
    visit.value = '';
    change();
    assert.equal(shared.disabled, false);
    assert.equal(exclusive.disabled, false);
    assert.equal(master.disabled, false);
    assert.equal(packages.value, 'shared');
});

test('pages without registration controls do not fail', () => {
    assert.doesNotThrow(() => runInNewContext(source, { document: { getElementById: () => null } }));
});
