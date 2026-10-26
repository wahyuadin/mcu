import assert from 'node:assert/strict';
import { test } from 'node:test';
import { labelTimestamp, validLabelSize, labelLayout, previewBarcode, focusLabelSubcategory, printLabels } from '../../resources/js/examination-label.js';

test('label time uses configured timezone and day:month:year hour:minute:second', () => {
    assert.equal(labelTimestamp('Asia/Jakarta', new Date('2026-10-12T07:30:45Z')), '12:10:2026 14:30:45');
});

test('label dimensions accept only bounded integer millimetres', () => {
    assert.equal(validLabelSize(80, 40), true);
    for (const [width, height] of [[39, 40], [301, 40], [80, 29], [80, 201], [80.5, 40], [NaN, 40]]) {
        assert.equal(validLabelSize(width, height), false);
    }
});

test('only QR labels with dominant length place the barcode on the right', () => {
    assert.equal(labelLayout('qr', 80, 40), 'horizontal');
    assert.equal(labelLayout('qr', 40, 80), 'vertical');
    assert.equal(labelLayout('qr', 40, 40), 'vertical');
    assert.equal(labelLayout('code128', 80, 40), 'vertical');
    assert.equal(labelLayout('code128', 40, 80), 'vertical');
});

test('preview selects the barcode belonging to the chosen category and subcategory', () => {
    const images = { lab: { urine: { qr: 'urine-qr', code128: 'urine-bars' }, blood: { qr: 'blood-qr', code128: 'blood-bars' } } };
    assert.equal(previewBarcode(images, 'lab', 'urine').qr, 'urine-qr');
    assert.equal(previewBarcode(images, 'lab', 'blood').code128, 'blood-bars');
    assert.equal(previewBarcode(images, 'lab', 'unknown'), null);
    assert.equal(previewBarcode(images, 'other', 'urine'), null);
    const sample = { qr: 'sample-qr', code128: 'sample-bars' };
    assert.equal(previewBarcode(sample, undefined, undefined), sample);
});

test('scanning focuses highlights and scrolls only to a subcategory on the matching tab', () => {
    let focus = 0;
    let scroll = 0;
    const target = { dataset: { examSubcategory: 'urine' }, focus(options) { assert.equal(options.preventScroll, true); focus++; }, scrollIntoView(options) { assert.equal(options.behavior, 'instant'); scroll++; } };
    const doc = { querySelector: () => ({ dataset: { examCategory: 'lab' }, querySelectorAll: () => [target] }) };
    assert.equal(focusLabelSubcategory(doc, '?tab=kategori-lab&subcategory=urine'), true);
    assert.equal(target.dataset.labelSelected, 'true');
    for (const query of ['?tab=kategori-other&subcategory=urine', '?tab=kategori-lab&subcategory=foreign', '?tab=kategori-lab', '?subcategory=urine', '?tab=kategori-lab&subcategory=%22%5Dscript']) {
        assert.equal(focusLabelSubcategory(doc, query), false);
    }
    assert.equal(focusLabelSubcategory({ querySelector: () => null }, '?tab=kategori-lab&subcategory=urine'), false);
    assert.equal(focus, 1);
    assert.equal(scroll, 1);
});

test('printing waits for barcode decoding and rejects overflow or missing documents', async () => {
    for (const scenario of ['valid', 'overflow', 'missing']) {
        let load;
        let prints = 0;
        let removed = false;
        let decoded = false;
        const status = { textContent: '' };
        const button = { disabled: false, textContent: 'Cetak' };
        const frame = {
            style: {}, setAttribute() {}, remove() { removed = true; },
            addEventListener(type, listener) { if (type === 'load') load = listener; },
            contentDocument: { querySelectorAll: () => scenario === 'missing' ? [] : [{
                scrollHeight: scenario === 'overflow' ? 200 : 100, clientHeight: 100,
                querySelector: () => ({ decode: async () => { decoded = true; } }),
            }] },
            contentWindow: { addEventListener() {}, focus() {}, print() { assert.equal(decoded, true); prints++; } },
        };
        globalThis.document = { createElement: () => frame, body: { append() {} } };
        try {
            await printLabels({ querySelector: () => status }, button, { url: '/label' });
            assert.equal(button.disabled, true);
            assert.equal(prints, 0);
            await load();
            assert.equal(button.disabled, false);
            assert.equal(prints, scenario === 'valid' ? 1 : 0);
            assert.equal(removed, scenario !== 'valid');
            if (scenario !== 'valid') assert.notEqual(status.textContent, '');
        } finally {
            delete globalThis.document;
        }
    }
});
