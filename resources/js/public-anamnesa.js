import { initializeAnswerNotes } from './examination-fields';

export function initializePublicAnamnesa(form) {
    initializeAnswerNotes(form);
    const sections = [...form.querySelectorAll('[data-form-section]')];
    const back = form.querySelector('[data-form-back]');
    const next = form.querySelector('[data-form-next]');
    const submit = form.querySelector('[data-form-submit]');
    const progress = form.querySelector('[data-form-progress]');
    const summary = form.querySelector('[data-review-answers]');
    let index = 0;
    let dirty = false;
    form.noValidate = true;
    const review = () => {
        summary.replaceChildren();
        form.querySelectorAll('[data-answer-card]').forEach((card) => {
            const row = document.createElement('div');
            const title = document.createElement('dt');
            const answer = document.createElement('dd');
            title.textContent = card.dataset.label;
            const selected = card.querySelector('[data-answer-input]') || card.querySelector('[data-tag-value]') || card.querySelector('input[type="radio"]:checked') || card.querySelector('textarea[name$="[answer]"]');
            const note = [...card.querySelectorAll('textarea')].find((field) => field.name.endsWith('[note]'));
            answer.textContent = `${selected?.value || 'Belum diisi'}${note?.value && !note.disabled ? ` — ${note.value}` : ''}`;
            row.className = 'min-w-0 border-b border-slate-200 pb-3';
            title.className = 'min-w-0 break-words';
            answer.className = 'min-w-0 whitespace-pre-line break-words font-semibold [overflow-wrap:anywhere]';
            row.append(title, answer);
            summary.append(row);
        });
    };
    const display = () => {
        sections.forEach((section, position) => { section.hidden = position !== index; });
        back.hidden = index === 0;
        next.hidden = index === sections.length - 1;
        submit.hidden = index !== sections.length - 1;
        progress.textContent = `Bagian ${index + 1} dari ${sections.length}`;
        if (index === sections.length - 1) review();
    };
    const invalidField = (section) => [...section.querySelectorAll('input, textarea')].find((field) => !field.checkValidity());
    const showError = (field) => {
        const message = field.closest?.('[data-answer-card]')?.querySelector('[data-client-error]');
        if (message) { message.textContent = 'Pertanyaan ini wajib diisi dengan jawaban yang sesuai.'; message.hidden = false; }
        field.reportValidity();
    };
    const move = (delta) => {
        if (delta > 0) {
            const tags = sections[index].querySelector?.('[data-complaint-tags]');
            if (tags && !tags.commitTag()) return;
            const invalid = invalidField(sections[index]);
            if (invalid) { showError(invalid); return; }
        }
        index += delta;
        display();
        progress.scrollIntoView({ block: 'start' });
    };
    back.addEventListener('click', () => move(-1));
    next.addEventListener('click', () => move(1));
    form.addEventListener('submit', (event) => {
        for (const tags of form.querySelectorAll('[data-complaint-tags]')) {
            if (!tags.commitTag()) { event.preventDefault(); return; }
        }
        for (let position = 0; position < sections.length; position++) {
            const invalid = invalidField(sections[position]);
            if (invalid) {
                event.preventDefault(); index = position; display(); showError(invalid); return;
            }
        }
        if (index !== sections.length - 1) { event.preventDefault(); index = sections.length - 1; display(); return; }
        submit.disabled = true;
        if (!form.hasAttribute?.('data-anamnesa-request')) dirty = false;
        submit.textContent = 'Mengirim…';
    });
    form.addEventListener('input', (event) => {
        dirty = true;
        const message = event?.target?.closest?.('[data-answer-card]')?.querySelector('[data-client-error]');
        if (message) message.hidden = true;
    });
    const beforeUnload = (event) => {
        if (!dirty) return;
        event.preventDefault();
        event.returnValue = '';
    };
    const pageShow = () => { submit.disabled = false; submit.textContent = 'Kirim anamnesa'; };
    window.addEventListener('beforeunload', beforeUnload);
    window.addEventListener('pageshow', pageShow);
    form.addEventListener('anamnesa:saved', () => { dirty = false; });
    form.addEventListener('anamnesa:failed', () => { dirty = true; submit.disabled = false; submit.textContent = 'Kirim anamnesa'; });
    form.addEventListener('anamnesa:validation', (event) => {
        const keys = Object.keys(event.detail);
        const position = sections.findIndex((section) => [...section.querySelectorAll('[data-answer-card]')].some((card) => keys.some((key) => key.startsWith(`answers.${card.dataset.answerKey}`) || (key.startsWith('complaint_tags') && card.dataset.answerKey === 'keluhan_saat_ini'))));
        if (position >= 0) index = position;
        display();
    });
    display();
    return () => { window.removeEventListener?.('beforeunload', beforeUnload); window.removeEventListener?.('pageshow', pageShow); };
}

export function normalizeComplaintTags(tags, added) {
    const value = added.trim();
    if (!value) return [...tags];
    if (value.length > 100) throw new Error('Setiap keluhan maksimal 100 karakter.');
    if (value.toLocaleLowerCase('id') === 'tidak ada keluhan') return ['Tidak ada keluhan'];
    const result = tags.filter((tag) => tag.toLocaleLowerCase('id') !== 'tidak ada keluhan');
    if (!result.some((tag) => tag.toLocaleLowerCase('id') === value.toLocaleLowerCase('id'))) result.push(value);
    if (result.length > 20 || result.join('; ').length > 2000) throw new Error('Maksimal 20 keluhan dan total 2.000 karakter.');
    return result;
}

export function initializeComplaintTags(root) {
    const editor = root.querySelector('[data-tag-editor]');
    const list = root.querySelector('[data-tag-list]');
    const answer = root.querySelector('[data-tag-value]');
    const error = root.querySelector('[data-tag-error]');
    let tags = [];
    try { JSON.parse(root.dataset.initialTags || '[]').forEach((tag) => { tags = normalizeComplaintTags(tags, tag); }); } catch { tags = []; }
    const render = () => {
        list.replaceChildren();
        tags.forEach((tag, index) => {
            const chip = document.createElement('span');
            chip.className = 'inline-flex max-w-full items-center gap-2 rounded-full bg-blue-50 px-3 py-2 text-sm text-blue-900';
            const text = document.createElement('span'); text.textContent = tag; text.className = 'break-words';
            const remove = document.createElement('button'); remove.type = 'button'; remove.textContent = '×'; remove.className = 'min-h-8 min-w-8'; remove.setAttribute('aria-label', `Hapus keluhan ${tag}`);
            remove.addEventListener('click', () => { tags.splice(index, 1); render(); answer.dispatchEvent(new Event('input', { bubbles: true })); editor.focus(); });
            const hidden = document.createElement('input'); hidden.type = 'hidden'; hidden.name = 'complaint_tags[]'; hidden.value = tag;
            chip.append(text, remove, hidden); list.append(chip);
        });
        answer.value = tags.join('; ');
        answer.setCustomValidity(tags.length ? '' : 'Tambahkan keluhan atau pilih “Tidak ada keluhan”.');
        root.querySelector('[data-tag-none]').setAttribute('aria-pressed', String(tags.length === 1 && tags[0] === 'Tidak ada keluhan'));
    };
    root.commitTag = () => {
        try {
            tags = normalizeComplaintTags(tags, editor.value); editor.value = ''; error.hidden = true; render();
            answer.dispatchEvent(new Event('input', { bubbles: true })); return true;
        } catch (failure) { error.textContent = failure.message; error.hidden = false; editor.focus(); return false; }
    };
    editor.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.isComposing) { event.preventDefault(); root.commitTag(); }
        if (event.key === 'Backspace' && !editor.value && tags.length) { tags.pop(); render(); answer.dispatchEvent(new Event('input', { bubbles: true })); }
    });
    root.querySelector('[data-tag-add]').addEventListener('click', () => root.commitTag());
    root.querySelector('[data-tag-none]').addEventListener('click', () => { editor.value = 'Tidak ada keluhan'; root.commitTag(); });
    answer.addEventListener('invalid', (event) => { event.preventDefault(); editor.focus(); });
    render();
}

export async function postAnamnesa(form, request = fetch) {
    const response = await request(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
    if (!response.ok) {
        if (response.status === 422) return { errors: (await response.json()).errors || {} };
        const messages = { 403: 'Sesi berakhir atau formulir tidak tersedia. Identifikasi kembali atau hubungi petugas.', 410: 'Kunjungan sudah ditutup. Hubungi petugas.', 419: 'Sesi keamanan berakhir. Salin jawaban Anda sebelum memuat ulang halaman.', 429: 'Terlalu banyak percobaan. Tunggu satu menit lalu coba kembali.' };
        throw new Error(messages[response.status] || 'Gagal mengirim. Jawaban tetap di halaman ini; coba kembali.');
    }
    return { html: await response.text() };
}

export function initializeAnamnesaConfirmation(dialog) {
    let resolve;
    let animation;
    let closing = false;
    let trigger;
    const cancel = dialog.querySelector('[data-confirm-cancel]');
    const accept = dialog.querySelector('[data-confirm-accept]');
    const fade = (from, to, exiting = false) => {
        animation?.cancel();
        const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        const easing = getComputedStyle(dialog).getPropertyValue('--ease-out').trim() || 'cubic-bezier(0.23, 1, 0.32, 1)';
        animation = dialog.animate?.([{ opacity: from }, { opacity: to }], { duration: reduced ? 125 : (exiting ? 200 : 220), easing, fill: 'both' });
        return animation?.finished.catch(() => {}) ?? Promise.resolve();
    };
    const finish = async (confirmed) => {
        if (!dialog.open || closing) return;
        closing = true;
        dialog.setAttribute('data-confirm-closing', '');
        await fade(getComputedStyle(dialog).opacity, 0, true);
        dialog.close();
        animation?.cancel();
        dialog.removeAttribute('data-confirm-closing');
        closing = false;
        const complete = resolve;
        resolve = null;
        trigger?.focus();
        complete?.(confirmed);
    };
    cancel.addEventListener('click', () => finish(false));
    accept.addEventListener('click', () => finish(true));
    dialog.addEventListener('cancel', (event) => { event.preventDefault(); finish(false); });
    dialog.addEventListener('click', (event) => { if (event.target === dialog) finish(false); });
    return (button) => {
        if (!dialog.showModal || dialog.open || closing) return Promise.resolve(false);
        trigger = button;
        return new Promise((complete) => {
            resolve = complete;
            dialog.showModal();
            cancel.focus();
            fade(0, 1);
        });
    };
}

export function initializeAnamnesaApp(root) {
    root.querySelectorAll('[data-complaint-tags]').forEach(initializeComplaintTags);
    const examination = root.querySelector('[data-public-anamnesa]');
    const cleanup = examination ? initializePublicAnamnesa(examination) : () => {};
    const confirmation = root.querySelector('[data-anamnesa-confirm]');
    const confirmReset = confirmation ? initializeAnamnesaConfirmation(confirmation) : async () => false;
    let busy = false;
    root.querySelectorAll('[data-anamnesa-request]').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            if (event.defaultPrevented) return;
            event.preventDefault();
            if (busy) return;
            busy = true;
            if (form.hasAttribute('data-anamnesa-reset') && examination && !await confirmReset(event.submitter || form.querySelector('button'))) {
                busy = false;
                return;
            }
            const status = root.querySelector('[data-spa-status]'); status.hidden = true;
            root.querySelectorAll('[data-spa-error]').forEach((error) => error.remove());
            const buttons = [...root.querySelectorAll('button')];
            const disabled = buttons.map((button) => button.disabled);
            try {
                const pending = postAnamnesa(form);
                buttons.forEach((button) => { button.disabled = true; });
                const result = await pending;
                if (result.errors) {
                    status.textContent = Object.values(result.errors).flat().join(' '); status.hidden = false;
                    Object.entries(result.errors).forEach(([key, messages]) => {
                        const questionKey = key.startsWith('complaint_tags') ? 'keluhan_saat_ini' : (key.startsWith('answers.') ? key.split('.')[1] : null);
                        const card = [...form.querySelectorAll('[data-answer-card]')].find((card) => card.dataset.answerKey === questionKey);
                        if (card) {
                            const error = card.querySelector('[data-client-error]');
                            error.textContent = messages.join(' '); error.hidden = false;
                            return;
                        }
                        const name = key.split('.').reduce((result, part, index) => index ? `${result}[${part}]` : part, '');
                        const field = form.elements.namedItem(name);
                        if (field?.closest) {
                            const message = document.createElement('span'); message.dataset.spaError = ''; message.className = 'text-sm text-red-700'; message.textContent = messages.join(' ');
                            (field.closest('label') || field.closest('[data-answer-card]'))?.append(message);
                        }
                    });
                    form.dispatchEvent(new CustomEvent('anamnesa:validation', { detail: result.errors }));
                    form.dispatchEvent(new Event('anamnesa:failed')); status.scrollIntoView({ block: 'center' });
                    return;
                }
                const page = new DOMParser().parseFromString(result.html, 'text/html');
                const replacement = page.querySelector('[data-anamnesa-app]');
                if (!replacement) throw new Error('Respons formulir tidak valid. Coba kembali; jawaban belum dihapus.');
                examination?.dispatchEvent(new Event('anamnesa:saved'));
                cleanup();
                root.replaceWith(replacement); initializeAnamnesaApp(replacement);
                replacement.querySelector('h1')?.setAttribute('tabindex', '-1'); replacement.querySelector('h1')?.focus();
                window.scrollTo({ top: 0, behavior: 'instant' });
            } catch (error) {
                status.textContent = error.message || 'Koneksi terputus. Coba kembali; jawaban belum dihapus.'; status.hidden = false;
                form.dispatchEvent(new Event('anamnesa:failed'));
            } finally {
                busy = false; buttons.forEach((button, index) => { button.disabled = disabled[index]; });
                const submit = form.querySelector('[data-form-submit]'); if (submit) submit.disabled = false;
            }
        });
    });
}

const app = document.querySelector('[data-anamnesa-app]');
if (app) initializeAnamnesaApp(app);

export function initializeBarcodeModal(dialog, forms, closeButton) {
    let animation;
    let closing = false;
    let trigger;
    const fade = (from, to, exiting = false) => {
        animation?.cancel();
        const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        const easing = getComputedStyle(dialog).getPropertyValue('--ease-out').trim() || 'cubic-bezier(0.23, 1, 0.32, 1)';
        animation = dialog.animate?.([{ opacity: from }, { opacity: to }], { duration: reduced ? 125 : (exiting ? 200 : 220), easing, fill: 'both' });
        return animation?.finished.catch(() => {}) ?? Promise.resolve();
    };
    const close = async () => {
        if (!dialog.open || closing) return;
        closing = true;
        const opacity = getComputedStyle(dialog).opacity;
        await fade(opacity, 0, true);
        dialog.close();
        animation?.cancel();
        closing = false;
        trigger?.focus();
    };
    forms.forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!dialog.showModal) return;
            if (closing || dialog.open) { event.preventDefault(); return; }
            trigger = form.querySelector('button');
            form.target = 'anamnesa-barcode-frame';
            dialog.showModal();
            fade(0, 1);
        });
    });
    closeButton?.addEventListener('click', close);
    dialog.addEventListener('cancel', (event) => { event.preventDefault(); close(); });
}

const dialog = document.querySelector('[data-barcode-dialog]');
if (dialog) initializeBarcodeModal(dialog, document.querySelectorAll('[data-barcode-form]'), document.querySelector('[data-barcode-close]'));
document.querySelector('[data-copy-anamnesa]')?.addEventListener('click', async () => {
    const input = document.querySelector('[data-anamnesa-url]');
    const status = document.querySelector('[data-copy-status]');
    try { await navigator.clipboard.writeText(input.value); status.textContent = 'Tautan berhasil disalin.'; }
    catch { input.select(); status.textContent = 'Pilih dan salin tautan secara manual.'; }
});
