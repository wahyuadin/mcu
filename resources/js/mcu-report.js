const report = document.querySelector('[data-mcu-report]');

if (report) {
    const sources = [...report.querySelectorAll('[data-report-source]')].map((page) => page.cloneNode(true));
    const printButton = document.querySelector('[data-report-print]');
    const status = document.querySelector('[data-report-status]');

    const paginate = () => {
        report.querySelectorAll('[data-report-source], [data-generated-page]').forEach((page) => page.remove());

        for (const source of sources) {
            const pages = [];
            const columns = source.querySelector('[data-columns]');
            const makePage = (index) => {
                if (pages[index]) return pages[index];
                const page = source.cloneNode(true);
                page.removeAttribute('data-report-source');
                page.dataset.generatedPage = '';
                const body = page.querySelector('[data-page-body]');
                const title = body.querySelector('[data-section-title]')?.cloneNode(true);
                body.replaceChildren();
                if (title && !(index > 0 && source.dataset.reportKind === 'laboratory')) {
                    if (index > 0) title.textContent += ' (LANJUTAN)';
                    body.append(title);
                }
                if (columns) {
                    const container = columns.cloneNode(false);
                    for (let column = 0; column < 2; column++) {
                        const target = document.createElement('div');
                        target.className = 'exam-column';
                        target.dataset.column = column;
                        container.append(target);
                    }
                    body.append(container);
                }
                report.append(page);
                const identity = page.querySelector('.report-identity');
                if (identity) {
                    const extraHeight = Math.max(0, identity.getBoundingClientRect().bottom - body.getBoundingClientRect().top + 8);
                    body.style.top = `${body.offsetTop + extraHeight}px`;
                    body.style.height = `${body.clientHeight - extraHeight}px`;
                }
                pages[index] = page;
                return page;
            };
            const bodyFor = (index) => makePage(index).querySelector('[data-page-body]');
            const targetFor = (index, column) => column === null ? bodyFor(index) : makePage(index).querySelector(`[data-column="${column}"]`);
            const overflows = (node, index) => node.getBoundingClientRect().bottom > bodyFor(index).getBoundingClientRect().bottom + 0.5;

            const addGroup = (group, start, column = null) => {
                let index = start;
                let shell;
                let tbody;
                let consumedRows = 0;
                const newShell = () => {
                    shell = group.cloneNode(true);
                    tbody = shell.querySelector('tbody');
                    tbody.replaceChildren();
                    if (consumedRows > 0 && shell.querySelector('h3') && !shell.querySelector('h3').textContent.includes('(LANJUTAN)')) shell.querySelector('h3').textContent += ' (LANJUTAN)';
                    targetFor(index, column).append(shell);
                };
                newShell();
                for (const original of group.querySelectorAll('tbody > tr')) {
                    let row = original.cloneNode(true);
                    tbody.append(row);
                    if (overflows(shell, index) && tbody.children.length > 1) {
                        row.remove();
                        const subheading = tbody.lastElementChild?.matches('.exam-subheading') ? tbody.lastElementChild : null;
                        subheading?.remove();
                        if (!tbody.children.length) shell.remove();
                        index++;
                        newShell();
                        if (subheading) tbody.append(subheading);
                        tbody.append(row);
                    } else if (overflows(shell, index) && targetFor(index, column).children.length > 1) {
                        shell.remove();
                        index++;
                        newShell();
                        tbody.append(row);
                    }

                    // Each cell is split independently so short numeric results remain intact.
                    while (overflows(shell, index)) {
                        const cells = [...row.cells];
                        const texts = cells.map((cell) => cell.textContent);
                        cells.forEach(cell => { cell.textContent = ''; });
                        const remaining = texts.map((text, cellIndex) => {
                            const cell = cells[cellIndex];
                            cell.textContent = text;
                            if (!overflows(shell, index)) return '';
                            let low = 0, high = text.length, fit = 0;
                            while (low <= high) {
                                const middle = Math.floor((low + high) / 2);
                                cell.textContent = text.slice(0, middle);
                                if (overflows(shell, index)) high = middle - 1;
                                else { fit = middle; low = middle + 1; }
                            }
                            const word = text.lastIndexOf(' ', fit);
                            if (word > fit * 0.7) fit = word + 1;
                            if (fit > 0 && /[\uD800-\uDBFF]/.test(text[fit - 1])) fit--;
                            cell.textContent = text.slice(0, fit);
                            return text.slice(fit);
                        });
                        if (overflows(shell, index) || remaining.every((text, cellIndex) => text === texts[cellIndex])) {
                            throw new Error('A report row cannot fit on an A4 page.');
                        }
                        const remainder = row.cloneNode(true);
                        remaining.forEach((text, cellIndex) => { remainder.cells[cellIndex].textContent = text; });
                        if (remaining[0] === '' && texts[0].length < 160) remainder.cells[0].textContent = texts[0];
                        consumedRows++;
                        index++;
                        newShell();
                        row = remainder;
                        tbody.append(row);
                    }
                    consumedRows++;
                }
                return index;
            };

            const addBlock = (original, start) => {
                let index = start;
                let block = original.cloneNode(true);
                targetFor(index, null).append(block);
                if (overflows(block, index) && bodyFor(index).children.length > (columns ? 2 : 1)) {
                    block.remove();
                    index++;
                    targetFor(index, null).append(block);
                }
                while (overflows(block, index)) {
                    const textNode = block.querySelector('p, .recommendations') ?? block;
                    const text = textNode.textContent;
                    if (text.length < 2 || block.querySelector('img')) throw new Error('A report block cannot fit on an A4 page.');
                    let low = 1, high = text.length - 1, fit = 0;
                    while (low <= high) {
                        const middle = Math.floor((low + high) / 2);
                        textNode.textContent = text.slice(0, middle);
                        if (overflows(block, index)) high = middle - 1;
                        else { fit = middle; low = middle + 1; }
                    }
                    if (fit === 0) throw new Error('Report text cannot fit on an A4 page.');
                    const word = text.lastIndexOf(' ', fit);
                    if (word > fit * 0.7) fit = word + 1;
                    if (fit > 0 && /[\uD800-\uDBFF]/.test(text[fit - 1])) fit--;
                    textNode.textContent = text.slice(0, fit);
                    const remainder = block.cloneNode(true);
                    (remainder.querySelector('p, .recommendations') ?? remainder).textContent = text.slice(fit);
                    index++;
                    targetFor(index, null).append(remainder);
                    block = remainder;
                }
                return pages.length - 1;
            };

            let index = 0;
            makePage(0);
            if (columns) {
                for (let column = 0; column < 2; column++) {
                    let columnIndex = 0;
                    for (const group of columns.querySelector(`[data-column="${column}"]`).children) {
                        columnIndex = addGroup(group, columnIndex, column);
                    }
                    index = Math.max(index, columnIndex);
                }
            }
            let referencePage = null;
            for (const block of source.querySelector('[data-page-body]').children) {
                if (block.matches('[data-section-title], [data-columns]')) continue;
                if (block.dataset.referencePage !== undefined) {
                    const nextReference = block.dataset.referencePage;
                    if (referencePage !== null && nextReference !== referencePage) index++;
                    referencePage = nextReference;
                }
                index = block.matches('[data-report-group]') ? addGroup(block, index) : addBlock(block, index);
            }
        }

        const pages = [...report.querySelectorAll('.report-page')];
        pages.forEach((page, index) => {
            page.querySelector('[data-page-number]').textContent = `${index + 1} / ${pages.length}`;
        });
        const overflow = pages.some((page) => [...(page.querySelector('[data-page-body]')?.children ?? [])].some((node) => node.getBoundingClientRect().bottom > page.querySelector('[data-page-body]').getBoundingClientRect().bottom + 1));
        report.dataset.ready = overflow ? 'overflow' : 'true';
        status.textContent = overflow ? 'Ada isi terlalu panjang. Periksa halaman sebelum mencetak.' : `${pages.length} halaman A4 · Skala 100% · Nonaktifkan header/footer browser.`;
        printButton.disabled = overflow;
    };

    const prepare = async () => {
        await document.fonts.ready;
        const imageStates = await Promise.all([...report.querySelectorAll('img')].map((image) => image.decode().then(() => true, () => false)));
        if (imageStates.some(loaded => !loaded)) throw new Error('A report image could not be loaded.');
        paginate();
    };
    prepare().catch(() => {
        status.textContent = 'Pembagian halaman gagal. Muat ulang laporan sebelum mencetak.';
    });
    printButton.addEventListener('click', () => { if (report.dataset.ready === 'true') window.print(); });
}
