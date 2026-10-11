import assert from 'node:assert/strict';
import { test } from 'node:test';
import { evaluateLaboratory, setupLaboratoryRow } from '../../resources/js/laboratory-results.js';

const config = { result_type: 'number', rule: 'range', min: 0, max: 10, normal_report: 'Dalam batas normal', abnormal_report: 'Di luar batas normal' };

test('laboratory evaluation keeps empty and invalid results distinct from normal, including zero', () => {
    for (const answer of [null, '', ' ']) assert.deepEqual(evaluateLaboratory(config, answer), { status: 'Belum diperiksa', remark: '' });
    for (const answer of ['abc', '1e999']) assert.deepEqual(evaluateLaboratory(config, answer), { status: 'Tidak dapat dibandingkan', remark: '' });
    for (const answer of ['0', '10', '5.5']) assert.equal(evaluateLaboratory(config, answer).status, 'Normal');
    for (const answer of ['-0.01', '10.01']) assert.equal(evaluateLaboratory(config, answer).status, 'Tidak normal');
});

test('strict and inclusive numeric boundaries agree with configured operators', () => {
    for (const [rule, answer, expected] of [['lt', 10, false], ['lt', 9.9, true], ['lte', 10, true], ['lte', 10.1, false], ['gt', 0, false], ['gt', 0.1, true], ['gte', 0, true], ['gte', -1, false]]) {
        assert.equal(evaluateLaboratory({ ...config, rule }, answer).status, expected ? 'Normal' : 'Tidak normal');
    }
});

test('text references compare exact normalized values and allow empty remarks', () => {
    const text = { ...config, result_type: 'text', rule: 'text', normal_values: ['Negatif', 'Tidak ditemukan'], normal_report: '' };
    assert.deepEqual(evaluateLaboratory(text, ' negatif '), { status: 'Normal', remark: '' });
    assert.equal(evaluateLaboratory(text, 'Positif').remark, config.abnormal_report);
    assert.equal(evaluateLaboratory(text, 'Bukan Negatif').status, 'Tidak normal');
});

test('typing and clearing results updates status and remarks without HTML injection', () => {
    const input = { value: '', addEventListener: (_, callback) => { input.update = callback; } };
    const status = {};
    const remark = {};
    const row = { dataset: { config: JSON.stringify({ ...config, abnormal_report: '<script>bad</script>' }) }, querySelector: (selector) => ({ '[data-laboratory-answer]': input, '[data-laboratory-status]': status, '[data-laboratory-remark]': remark })[selector] };
    setupLaboratoryRow(row);
    assert.equal(status.textContent, 'Belum diperiksa');
    input.value = '11'; input.update();
    assert.equal(remark.textContent, '<script>bad</script>');
    input.value = '0'; input.update();
    assert.equal(status.textContent, 'Normal');
    input.value = ''; input.update();
    assert.equal(remark.textContent, '');
});
