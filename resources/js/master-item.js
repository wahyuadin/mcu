export function configureQuestionCard(card) {
    const type = card.querySelector('[data-question-field="input_type"]');
    const mapping = card.querySelector('[data-radio-mapping]');
    const hint = card.querySelector('[data-answer-hint]');
    const update = () => {
        const radio = type.value === 'radio';
        mapping.hidden = !radio;
        mapping.disabled = !radio;
        mapping.querySelectorAll('textarea').forEach((field) => { field.required = radio; });
        hint.hidden = radio;
        hint.textContent = type.value === 'number' ? 'Jawaban berupa angka' : 'Jawaban berupa teks';
    };
    type.addEventListener('change', update);
    update();
}

export function questionValues(card) {
    const values = {};
    card.querySelectorAll('[data-question-field]').forEach((field) => {
        values[field.dataset.questionField] = field.type === 'checkbox' ? field.checked : field.value;
    });
    return values;
}

export function renameQuestion(card, index) {
    card.querySelectorAll('[name]').forEach((field) => {
        field.name = field.name.replace(/questions\[\d+\]/, `questions[${index}]`);
    });
    card.querySelector('[data-question-number]').textContent = `Pertanyaan ${index + 1}`;
}

export function setupFormBuilder(form, root = document) {
    const container = form.querySelector('[data-builder-cards]');
    const template = form.querySelector('[data-builder-template]');
    const preview = form.querySelector('[data-builder-preview]');
    const previewToggle = form.querySelector('[data-builder-preview-toggle]');
    const toolbar = form.querySelector('[data-builder-toolbar]');
    const status = form.querySelector('[data-builder-status]');
    const cards = () => [...container.querySelectorAll('[data-question-card]')];
    const renumber = () => cards().forEach(renameQuestion);
    cards().forEach(configureQuestionCard);

    const addCard = (source = null) => {
        if (cards().length >= 50) { status.textContent = 'Maksimal 50 pertanyaan dalam satu penyimpanan.'; return; }
        const card = source ? source.cloneNode(true) : template.content.firstElementChild.cloneNode(true);
        if (source) {
            const originalFields = [...source.querySelectorAll('[data-question-field]')];
            card.querySelectorAll('[data-question-field]').forEach((field, index) => {
                field.value = originalFields[index].value;
                field.checked = originalFields[index].checked;
            });
        }
        card.removeAttribute('data-primary-question');
        card.querySelector('[data-question-remove]').hidden = false;
        if (source) source.after(card);
        else container.append(card);
        renumber();
        configureQuestionCard(card);
        card.querySelector('[data-question-field="label"]').focus();
        status.textContent = source ? 'Pertanyaan diduplikasi. Ubah isinya lalu simpan.' : 'Pertanyaan baru ditambahkan. Belum disimpan.';
    };
    form.querySelector('[data-builder-add]').addEventListener('click', () => addCard());
    container.addEventListener('click', (event) => {
        const card = event.target.closest('[data-question-card]');
        if (!card) return;
        if (event.target.closest('[data-question-duplicate]')) addCard(card);
        if (event.target.closest('[data-question-remove]') && !card.hasAttribute('data-primary-question')) {
            if (cards().length === 1) { status.textContent = 'Form harus memiliki minimal satu pertanyaan.'; return; }
            card.remove();
            renumber();
            status.textContent = 'Kartu pertanyaan dihapus dari draft. Item tersimpan tidak dihapus.';
        }
    });

    const renderPreview = () => {
        preview.replaceChildren();
        cards().forEach((card) => {
            const values = questionValues(card);
            const section = root.createElement('section');
            section.className = 'grid gap-4 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm';
            const title = root.createElement('h3');
            title.className = 'break-words font-semibold';
            title.textContent = `${values.label || 'Pertanyaan tanpa judul'}${values.is_required ? ' *' : ''}`;
            section.append(title);
            if (values.help_text) {
                const help = root.createElement('p');
                help.className = 'whitespace-pre-line text-sm text-slate-500';
                help.textContent = values.help_text;
                section.append(help);
            }
            if (values.input_type === 'radio') {
                ['YA', 'TIDAK'].forEach((answer) => {
                    const label = root.createElement('label');
                    label.className = 'flex items-center gap-3 text-sm';
                    const radio = root.createElement('input');
                    radio.type = 'radio';
                    radio.disabled = true;
                    const text = root.createElement('span');
                    text.textContent = answer;
                    label.append(radio, text);
                    section.append(label);
                });
            } else {
                const input = root.createElement('input');
                input.type = values.input_type === 'number' ? 'number' : 'text';
                input.disabled = true;
                input.placeholder = values.input_type === 'number' ? 'Jawaban berupa angka' : 'Jawaban berupa teks';
                input.className = 'rounded-xl border border-slate-200 px-4 py-3 text-sm';
                section.append(input);
            }
            preview.append(section);
        });
    };
    previewToggle.addEventListener('click', () => {
        const show = preview.hidden;
        if (show) renderPreview();
        preview.hidden = !show;
        container.hidden = show;
        toolbar.hidden = show;
        previewToggle.textContent = show ? 'Kembali ke editor' : 'Pratinjau form';
        previewToggle.setAttribute('aria-pressed', String(show));
        status.textContent = show ? 'Pratinjau tampilan pertanyaan. Tidak ada jawaban atau data pasien yang disimpan.' : 'Mode edit pertanyaan.';
    });
    const returnToEditor = () => {
        container.hidden = false;
        toolbar.hidden = false;
        preview.hidden = true;
        previewToggle.textContent = 'Pratinjau form';
        previewToggle.setAttribute('aria-pressed', 'false');
    };
    form.querySelector('[data-builder-save]').addEventListener('click', returnToEditor);
    form.addEventListener('submit', returnToEditor);
}

if (typeof document !== 'undefined') {
    document.querySelectorAll('[data-form-builder]').forEach((form) => setupFormBuilder(form));
}
