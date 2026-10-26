const initialized = new WeakSet();

export function initializeAnswerNotes(root) {
    root.querySelectorAll('[data-conditional-answer]').forEach((card) => {
        if (initialized.has(card)) return;
        initialized.add(card);
        const note = card.querySelector('[data-conditional-note]');
        if (!note) return;
        const update = () => {
            const answer = card.querySelector('input[type="radio"]:checked') || card.querySelector('select');
            const visible = answer?.value.toUpperCase() === 'YA';
            note.hidden = !visible;
            if (note.tagName === 'FIELDSET') note.disabled = !visible;
            note.querySelectorAll('textarea, input').forEach((field) => { field.disabled = !visible; });
        };
        card.addEventListener('change', update);
        update();
    });
}

export function calculateBmi(height, weight) {
    if (String(height).trim() === '' || String(weight).trim() === '') return null;
    const h = Number(height), w = Number(weight);
    if (!Number.isFinite(h) || !Number.isFinite(w) || h <= 0 || w <= 0) return null;
    const bmi = Math.round((w / ((h / 100) ** 2) + Number.EPSILON) * 100) / 100;
    if (!Number.isFinite(bmi)) return null;
    const category = bmi < 18.5 ? 'Berat Badan Kurang (Underweight)' : bmi < 23 ? 'Normal' : bmi < 25 ? 'Kelebihan Berat Badan (Overweight)' : bmi < 30 ? 'Obesitas I' : 'Obesitas II';
    return { bmi, category };
}

export function initializeBmi(root) {
    root.querySelectorAll('[data-bmi]').forEach((output) => {
        if (initialized.has(output)) return;
        const form = output.closest('form') || root;
        const measurement = (key) => form.querySelector(`[data-physical-key="${key}"] input, [data-physical-key="${key}"] textarea`) || form.querySelector(`[name="${key}"]`);
        const height = measurement('tinggi_badan'), weight = measurement('berat_badan');
        if (!height || !weight) return;
        initialized.add(output);
        const update = () => {
            const result = calculateBmi(height.value, weight.value);
            output.textContent = result ? `${result.bmi.toFixed(2)} · ${result.category}` : 'Belum dapat dihitung';
        };
        height.addEventListener('input', update);
        weight.addEventListener('input', update);
        update();
    });
}

if (typeof document !== 'undefined') {
    const mount = () => { initializeAnswerNotes(document); initializeBmi(document); };
    mount();
    document.addEventListener('mcu:exam-mounted', mount);
}
