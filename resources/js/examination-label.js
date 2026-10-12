export function labelTimestamp(timezone, date = new Date()) {
    const parts = new Intl.DateTimeFormat('en-GB', {
        timeZone: timezone, day: '2-digit', month: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23',
    }).formatToParts(date);
    const values = Object.fromEntries(parts.map(({ type, value }) => [type, value]));
    return `${values.day}:${values.month}:${values.year} ${values.hour}:${values.minute}:${values.second}`;
}

export function validLabelSize(width, height) {
    return Number.isInteger(width) && width >= 40 && width <= 300
        && Number.isInteger(height) && height >= 30 && height <= 200;
}

export function labelLayout(type, width, height) {
    return type === 'qr' && width > height ? 'horizontal' : 'vertical';
}

export function previewBarcode(images, categoryId, subcategoryId) {
    return images[categoryId]?.[subcategoryId] || (images.qr ? images : null);
}

export function focusLabelSubcategory(document, search) {
    const query = new URLSearchParams(search);
    const section = document.querySelector('[data-exam-category]');
    if (!section || query.get('tab') !== `kategori-${section.dataset.examCategory}`) return false;
    const target = [...section.querySelectorAll('[data-exam-subcategory]')]
        .find((row) => row.dataset.examSubcategory === query.get('subcategory'));
    if (!target) return false;
    target.dataset.labelSelected = 'true';
    target.focus({ preventScroll: true });
    target.scrollIntoView({ behavior: 'instant', block: 'start' });
    return true;
}

function setupPreview(root) {
    const styles = document.createElement('style');
    styles.textContent = root.querySelector('[data-label-styles]').content.textContent;
    document.head.append(styles);
    const images = JSON.parse(root.querySelector('[data-label-barcodes]').textContent);
    const preview = root.querySelector('[data-label-preview]');
    return (category, subcategory, subcategoryId = null) => {
        const valid = category && validLabelSize(category.width, category.height);
        preview.hidden = !valid;
        root.querySelector('[data-label-warning]').textContent = '';
        if (!category) {
            root.querySelector('[data-label-warning]').textContent = 'Tidak ada kategori pemeriksaan dalam paket peserta ini.';
            return false;
        }
        if (!valid) {
            root.querySelector('[data-label-warning]').textContent = 'Ukuran label harus 40–300 × 30–200 mm, dalam angka bulat.';
            return false;
        }
        preview.style.setProperty('--label-width', `${category.width}mm`);
        preview.style.setProperty('--label-height', `${category.height}mm`);
        preview.dataset.barcodeType = category.type;
        preview.dataset.labelLayout = labelLayout(category.type, category.width, category.height);
        preview.querySelector('[data-label-heading]').textContent = `${subcategory || 'Subkategori'} - ${category.name || 'Kategori'}`;
        preview.querySelector('[data-label-time]').textContent = labelTimestamp(root.dataset.labelTimezone);
        const barcode = previewBarcode(images, category.id, subcategoryId);
        if (!barcode) {
            preview.hidden = true;
            return false;
        }
        preview.querySelector('[data-label-image]').src = barcode[category.type];
        if (category.type === 'code128' && (category.width - 4) / barcode.modules < 0.19) {
            root.querySelector('[data-label-warning]').textContent = 'URL panjang membuat Code 128 sangat padat. Gunakan QR atau label lebih panjang dan uji scan pada printer sebelum dipakai.';
        }
        return true;
    };
}

export async function printLabels(root, button, source) {
    root.labelPrintFrame?.remove();
    const status = root.querySelector('[data-label-warning]');
    const frame = document.createElement('iframe');
    frame.title = 'Label untuk dicetak';
    frame.setAttribute('aria-hidden', 'true');
    frame.tabIndex = -1;
    root.labelPrintFrame = frame;
    Object.assign(frame.style, { position: 'fixed', left: '0', top: '0', width: '1200px', height: '900px', opacity: '0', pointerEvents: 'none', zIndex: '-1', border: '0' });
    button.disabled = true;
    const text = button.textContent;
    button.textContent = 'Menyiapkan…';
    const reset = () => { button.disabled = false; button.textContent = text; };
    let timeout;
    frame.addEventListener('load', async () => {
        try {
            const labels = [...frame.contentDocument.querySelectorAll('.mcu-label')];
            if (!labels.length) throw new Error('missing-labels');
            await Promise.all(labels.map(async (label) => {
                await label.querySelector('img').decode();
                if (label.scrollHeight > label.clientHeight + 1) throw new Error('overflow');
            }));
            clearTimeout(timeout);
            frame.contentWindow.addEventListener('afterprint', () => frame.remove(), { once: true });
            frame.contentWindow.focus();
            frame.contentWindow.print();
        } catch (error) {
            frame.remove();
            status.textContent = error.message === 'overflow'
                ? 'Isi label melebihi ukuran. Perbesar label di master kategori sebelum mencetak.'
                : 'Label tidak dapat dimuat. Pastikan sesi login dan pengaturan master masih aktif, lalu coba kembali.';
        } finally {
            clearTimeout(timeout);
            reset();
        }
    }, { once: true });
    if (source.url) frame.src = source.url;
    else frame.srcdoc = source.html;
    document.body.append(frame);
    timeout = setTimeout(() => {
        frame.remove(); reset();
        status.textContent = 'Label terlalu lama dimuat. Silakan coba kembali.';
    }, 30000);
}

if (typeof document !== 'undefined') {
    focusLabelSubcategory(document, window.location.search);
    document.querySelectorAll('[data-print-dropdown]').forEach((dropdown) => {
        dropdown.addEventListener('click', (event) => {
            if (event.target.closest('button')) dropdown.open = false;
        });
        document.addEventListener('click', (event) => {
            if (!dropdown.contains(event.target)) dropdown.open = false;
        });
        dropdown.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') { dropdown.open = false; dropdown.querySelector('summary').focus(); }
        });
    });

    document.querySelectorAll('[data-label-master]').forEach((root) => {
        const updatePreview = setupPreview(root);
        const form = root.closest('form');
        const print = root.querySelector('[data-label-sample-print]');
        const category = () => ({
            name: form.elements.namedItem('name').value,
            enabled: form.elements.namedItem('label_enabled').value === '1',
            type: form.elements.namedItem('label_barcode_type').value,
            width: Number(form.elements.namedItem('label_width_mm').value),
            height: Number(form.elements.namedItem('label_height_mm').value),
        });
        const update = () => {
            print.disabled = !updatePreview(category(), root.querySelector('[data-label-sample-subcategory]').value);
        };
        form.addEventListener('input', update);
        form.addEventListener('change', update);
        update();
        print.addEventListener('click', () => {
            update();
            if (print.disabled) return;
            const { width, height } = category();
            const page = document.implementation.createHTMLDocument('Contoh label');
            const style = page.createElement('style');
            style.textContent = root.querySelector('[data-label-styles]').content.textContent
                + `@page { size: ${width}mm ${height}mm; margin: 0; } body { margin: 0; }`;
            page.head.append(style);
            page.body.append(root.querySelector('[data-label-preview]').cloneNode(true));
            printLabels(root, print, { html: '<!doctype html>' + page.documentElement.outerHTML });
        });
    });

    document.querySelectorAll('[data-label-modal]').forEach((modal) => {
        const root = modal.querySelector('[data-label-picker]');
        const categories = JSON.parse(root.querySelector('[data-label-categories]').textContent);
        const select = root.querySelector('[data-label-category]');
        const choices = root.querySelector('[data-label-subcategories]');
        const print = root.querySelector('[data-label-print]');
        const updatePreview = setupPreview(root);
        const category = () => categories.find((row) => row.id === select.value);
        const selected = () => [...choices.querySelectorAll('input:checked')];
        const update = () => {
            const current = category();
            const checked = selected();
            const subcategory = current?.subcategories.find((row) => row.id === checked[0]?.value) || current?.subcategories[0];
            const valid = updatePreview(current, subcategory?.name, subcategory?.id);
            print.disabled = !current?.enabled || !checked.length || checked.length > 30 || !valid;
            root.querySelector('[data-label-count]').textContent = `${checked.length} label dipilih`;
            if (checked.length > 30) root.querySelector('[data-label-warning]').textContent = 'Pilih maksimal 30 subkategori dalam satu cetakan.';
            root.querySelector('[data-label-config]').textContent = current?.enabled
                ? `${current.type === 'qr' ? 'QR' : 'Code 128'} · ${current.width} × ${current.height} mm — mengikuti master kategori`
                : 'Cetak label belum diaktifkan di master kategori, atau master sudah dihapus.';
        };
        const renderChoices = () => {
            choices.replaceChildren();
            for (const subcategory of category()?.subcategories || []) {
                const label = document.createElement('label');
                label.className = 'flex min-w-0 items-start gap-3 rounded-xl border border-slate-200 p-3 text-sm';
                const input = document.createElement('input');
                input.type = 'checkbox'; input.value = subcategory.id; input.dataset.name = subcategory.name;
                input.className = 'mt-0.5 h-4 w-4 shrink-0 accent-[#00008B]';
                input.disabled = !category().enabled;
                const text = document.createElement('span'); text.textContent = subcategory.name; text.className = 'min-w-0 break-words';
                label.append(input, text); choices.append(label);
            }
            update();
        };
        select.addEventListener('change', renderChoices);
        choices.addEventListener('change', update);
        let trigger;
        document.querySelectorAll('[data-label-open]').forEach((button) => button.addEventListener('click', () => {
            trigger = button;
            renderChoices(); modal.showModal();
        }));
        modal.querySelectorAll('[data-label-close]').forEach((button) => button.addEventListener('click', () => modal.close()));
        modal.addEventListener('close', () => {
            const dropdown = trigger?.closest('details');
            (dropdown?.querySelector('summary') || trigger)?.focus();
        });
        modal.addEventListener('click', (event) => { if (event.target === modal && (event.clientX < modal.getBoundingClientRect().left || event.clientX > modal.getBoundingClientRect().right || event.clientY < modal.getBoundingClientRect().top || event.clientY > modal.getBoundingClientRect().bottom)) modal.close(); });
        print.addEventListener('click', () => {
            if (print.disabled) return;
            const url = new URL(root.dataset.labelUrl, window.location.href);
            url.searchParams.set('category', select.value);
            selected().forEach((input) => url.searchParams.append('subcategories[]', input.value));
            printLabels(root, print, { url: url.href });
        });
        renderChoices();
    });
}
