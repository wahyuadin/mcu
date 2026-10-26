import { test } from 'node:test';
import assert from 'node:assert/strict';
import { calculateBmi, initializeAnswerNotes, initializeBmi } from '../../resources/js/examination-fields.js';

test('BMI matches server rounding and category thresholds and rejects incomplete values', () => {
    assert.deepEqual(calculateBmi('170', '65'), { bmi: 22.49, category: 'Normal' });
    for (const value of ['', ' ', 'abc', '0', '-1', 'Infinity']) assert.equal(calculateBmi(value, '65'), null);
    assert.equal(calculateBmi('170', ''), null);
    for (const [weight, category] of [[18.49, 'Berat Badan Kurang (Underweight)'], [18.5, 'Normal'], [23, 'Kelebihan Berat Badan (Overweight)'], [25, 'Obesitas I'], [30, 'Obesitas II']]) {
        assert.equal(calculateBmi(100, weight).category, category);
    }
});

test('conditional notes preserve draft while hidden and are disabled until YES is selected', () => {
    const field = { value: 'Sejak 2020', disabled: false };
    const note = { tagName: 'FIELDSET', querySelectorAll: () => [field] };
    const answer = { value: 'TIDAK' };
    const events = {};
    const card = { querySelector: (selector) => selector === '[data-conditional-note]' ? note : answer, addEventListener: (name, fn) => { events[name] = fn; } };
    initializeAnswerNotes({ querySelectorAll: () => [card] });
    assert.equal(note.hidden, true);
    assert.equal(field.disabled, true);
    answer.value = 'Ya'; events.change();
    assert.equal(note.hidden, false);
    assert.equal(note.disabled, false);
    assert.equal(field.value, 'Sejak 2020');
    answer.value = ''; events.change();
    assert.equal(field.disabled, true);
});

test('BMI output changes on input without submitting and clears stale results', () => {
    const height = { value: '170', events: {}, addEventListener(name, fn) { this.events[name] = fn; } };
    const weight = { value: '65', events: {}, addEventListener(name, fn) { this.events[name] = fn; } };
    const form = { querySelector: (selector) => selector.includes('tinggi_badan') ? height : weight };
    const output = { closest: () => form };
    initializeBmi({ querySelectorAll: () => [output] });
    assert.equal(output.textContent, '22.49 · Normal');
    weight.value = '90'; weight.events.input();
    assert.equal(output.textContent, '31.14 · Obesitas II');
    height.value = ''; height.events.input();
    assert.equal(output.textContent, 'Belum dapat dihitung');
});
