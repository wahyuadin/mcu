import $ from 'jquery';
import installSelect2 from 'select2';

installSelect2(window, $);

export function mountModernSelects() {
    document.querySelectorAll('[data-examination-page] select, [data-package-template-select]').forEach((select) => {
        if ($(select).data('select2')) return;
        if (select.matches(':disabled')) select.disabled = true;
        const dialog = select.closest('dialog');
        $(select).select2({
            width: '100%',
            dropdownParent: dialog ? $(dialog) : $(document.body),
            minimumResultsForSearch: 0,
            language: { noResults: () => 'Tidak ada pilihan yang cocok.', searching: () => 'Mencari…' },
        }).on('change.mcu', (event) => {
            if (!event.originalEvent) select.dispatchEvent(new Event('change', { bubbles: true }));
        });
    });
}

mountModernSelects();
document.addEventListener('mcu:exam-mounted', mountModernSelects);
document.addEventListener('mcu:exam-unmount', () => {
    document.querySelectorAll('[data-examination-page] select').forEach((select) => {
        if ($(select).data('select2')) $(select).off('.mcu').select2('destroy');
    });
});
