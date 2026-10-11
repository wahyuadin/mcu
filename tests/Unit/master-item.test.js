import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';
import { configureQuestionCard, questionValues, renameQuestion, setupFormBuilder } from '../../resources/js/master-item.js';

const source = readFileSync(new URL('../../resources/js/master-item.js', import.meta.url), 'utf8');

test('mapping inputs are required only for radio questions and reappear when switching back', () => {
    let change;
    const type = { value: 'radio', addEventListener: (event, callback) => { change = callback; } };
    const fields = [{}, {}];
    const mapping = { querySelectorAll: () => fields };
    const hint = {};
    const card = { querySelector: (selector) => selector === '[data-question-field="input_type"]' ? type : (selector === '[data-radio-mapping]' ? mapping : (selector === '[data-answer-hint]' ? hint : null)) };
    configureQuestionCard(card);
    assert.equal(mapping.hidden, false);
    assert.equal(fields[0].required, true);
    type.value = 'text';
    change();
    assert.equal(mapping.hidden, true);
    assert.equal(mapping.disabled, true);
    assert.equal(fields[0].required, false);
    type.value = 'radio';
    change();
    assert.equal(mapping.hidden, false);
    assert.equal(mapping.disabled, false);
    assert.equal(fields[1].required, true);
});

test('pages without a master form are unaffected', () => {
    assert.doesNotThrow(() => runInNewContext(source.replace(/^export /gm, ''), { document: { querySelectorAll: () => [] } }));
});

test('laboratory editor requires only the bounds relevant to the selected result and rule', () => {
    const field = (value = '') => ({ value, events: {}, label: {}, addEventListener(event, callback) { this.events[event] = callback; }, closest() { return this.label; }, removeAttribute(name) { delete this[name]; } });
    const fields = Object.fromEntries(['lab_default_result', 'lab_rule', 'lab_min', 'lab_max', 'lab_normal_values', 'lab_reference'].map((name) => [name, field()]));
    fields.lab_reference.value = '0–10';
    fields.lab_rule.value = 'range';
    fields.lab_rule.options = ['range', 'lt', 'lte', 'gt', 'gte', 'text'].map((value) => ({ value }));
    const laboratory = { querySelector: (selector) => fields[selector.match(/data-question-field="(.*?)"/)[1]] };
    const type = field('number');
    const hint = {};
    const mapping = { querySelectorAll: () => [] };
    const card = { querySelector: (selector) => ({ '[data-question-field="input_type"]': type, '[data-radio-mapping]': mapping, '[data-answer-hint]': hint, '[data-laboratory-mapping]': laboratory })[selector] };
    configureQuestionCard(card);
    assert.equal(laboratory.disabled, false);
    assert.equal(fields.lab_min.required, true);
    assert.equal(fields.lab_max.required, true);
    assert.equal(fields.lab_normal_values.disabled, true);
    assert.equal(fields.lab_default_result.type, 'number');
    fields.lab_rule.value = 'lt'; fields.lab_rule.events.change();
    assert.equal(fields.lab_min.disabled, true);
    assert.equal(fields.lab_max.required, true);
    type.value = 'text'; type.events.change();
    assert.equal(fields.lab_rule.value, 'text');
    assert.equal(fields.lab_normal_values.required, true);
    assert.equal(fields.lab_max.disabled, true);
    type.value = 'number'; type.events.change();
    assert.equal(laboratory.hidden, false);
    assert.equal(fields.lab_default_result.type, 'number');
    assert.equal(fields.lab_min.required, true);
    type.value = 'text'; type.events.change();
    assert.equal(laboratory.hidden, false);
    assert.equal(fields.lab_default_result.type, 'text');
    assert.equal(fields.lab_default_result.min, undefined);
    assert.equal(fields.lab_rule.value, 'text');
    assert.equal(fields.lab_normal_values.required, true);
    fields.lab_reference.value = ''; fields.lab_reference.events.input();
    assert.equal(fields.lab_normal_values.required, false);
    fields.lab_default_result.value = 'Normal'; fields.lab_default_result.events.input();
    assert.equal(fields.lab_reference.required, true);
    assert.equal(fields.lab_normal_values.required, true);
    type.value = 'radio'; type.events.change();
    assert.equal(laboratory.disabled, true);
    assert.equal(fields.lab_reference.required, false);
});

class Field {
    constructor(value = '') { this.value = value; this.events = {}; this.dataset = {}; }
    addEventListener(event, callback) { this.events[event] = callback; }
    focus() { this.focused = true; }
    setAttribute(name, value) { this[name] = value; }
}

class Card {
    constructor(values = {}) {
        this.fields = Object.entries({ label: '', input_type: 'radio', help_text: '', yes_report: '', no_report: '', is_active: '1', is_required: true, ...values }).map(([key, value]) => {
            const field = new Field(value);
            field.dataset.questionField = key;
            field.name = `questions[0][${key}]`;
            if (key === 'is_required') { field.type = 'checkbox'; field.checked = value; field.value = '1'; }
            return field;
        });
        this.number = {};
        this.removeButton = {};
        this.hint = {};
        this.mapping = { querySelectorAll: () => this.fields.filter((field) => ['yes_report', 'no_report'].includes(field.dataset.questionField)) };
    }
    querySelector(selector) {
        if (selector === '[data-radio-mapping]') return this.mapping;
        if (selector === '[data-answer-hint]') return this.hint;
        if (selector === '[data-question-remove]') return this.removeButton;
        if (selector === '[data-question-number]') return this.number;
        const key = selector.match(/data-question-field="(.*?)"/)?.[1];
        return this.fields.find((field) => field.dataset.questionField === key);
    }
    querySelectorAll() { return this.fields; }
    cloneNode() { const result = new Card(questionValues(this)); result.primary = this.primary; return result; }
    removeAttribute() { this.primary = false; }
    hasAttribute() { return this.primary; }
    after(card) { card.owner = this.owner; this.owner.cards.splice(this.owner.cards.indexOf(this) + 1, 0, card); }
    remove() { this.owner.cards.splice(this.owner.cards.indexOf(this), 1); }
}

function builder() {
    const primary = new Card({ label: 'Hepatitis', no_report: 'Normal' });
    primary.primary = true;
    const container = new Field();
    container.cards = [primary];
    primary.owner = container;
    container.querySelectorAll = () => container.cards;
    container.append = (card) => { card.owner = container; container.cards.push(card); };
    const preview = new Field();
    preview.hidden = true;
    preview.children = [];
    preview.replaceChildren = () => { preview.children = []; };
    preview.append = (child) => preview.children.push(child);
    const controls = {
        '[data-builder-cards]': container, '[data-builder-template]': { content: { firstElementChild: new Card() } },
        '[data-builder-preview]': preview, '[data-builder-preview-toggle]': new Field(),
        '[data-builder-toolbar]': new Field(), '[data-builder-status]': new Field(),
        '[data-builder-add]': new Field(), '[data-builder-save]': new Field(),
    };
    const form = new Field();
    form.querySelector = (selector) => controls[selector];
    const root = { createElement: () => ({ children: [], append(...children) { this.children.push(...children); } }) };
    setupFormBuilder(form, root);
    const act = (card, selector) => container.events.click({ target: { closest: (target) => target === '[data-question-card]' ? card : (target === selector ? {} : null) } });
    return { form, controls, container, primary, act, preview };
}

test('question values include checkbox state and renumbering keeps field names unique', () => {
    const card = new Card({ label: 'Asma', is_required: false });
    renameQuestion(card, 3);
    assert.equal(card.fields[0].name, 'questions[3][label]');
    assert.equal(card.number.textContent, 'Pertanyaan 4');
    assert.equal(questionValues(card).is_required, false);
});

test('adding and duplicating cards preserves draft values and allows removing only unsaved cards', () => {
    const { controls, container, primary, act } = builder();
    primary.fields[0].value = 'Judul yang belum disimpan';
    act(primary, '[data-question-duplicate]');
    assert.equal(container.cards.length, 2);
    assert.equal(questionValues(container.cards[1]).label, 'Judul yang belum disimpan');
    assert.equal(questionValues(container.cards[1]).no_report, 'Normal');
    assert.equal(container.cards[1].fields[0].name, 'questions[1][label]');
    controls['[data-builder-add]'].events.click();
    assert.equal(container.cards.length, 3);
    act(primary, '[data-question-remove]');
    assert.equal(container.cards.length, 3);
    act(container.cards[1], '[data-question-remove]');
    assert.equal(container.cards.length, 2);
    assert.equal(container.cards[1].fields[0].name, 'questions[1][label]');
});

test('preview uses draft text safely and saving restores editable fields before validation', () => {
    const { controls, container, primary, preview } = builder();
    primary.fields[0].value = '<script>unsafe</script>';
    controls['[data-builder-preview-toggle]'].events.click();
    assert.equal(preview.hidden, false);
    assert.equal(container.hidden, true);
    assert.equal(preview.children[0].children[0].textContent, '<script>unsafe</script> *');
    controls['[data-builder-save]'].events.click();
    assert.equal(preview.hidden, true);
    assert.equal(container.hidden, false);
});
