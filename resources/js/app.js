import './patient-photo';
import './checkpoint-print';
import './planning-dates';
import './package-selection';
import './master-item';
import './item-order';
import './patient-import';
import './master-bulk-delete';
import './laboratory-results';
import './public-anamnesa';

const examForm = document.querySelector('[data-exam-form]');

if (examForm) {
    let dirty = false;
    examForm.addEventListener('input', () => {
        dirty = true;
        const status = examForm.querySelector('[data-save-status]');
        if (status) status.textContent = 'Ada perubahan yang belum disimpan';
        const height = Number(examForm.elements.namedItem('tinggi_badan')?.value);
        const weight = Number(examForm.elements.namedItem('berat_badan')?.value);
        const bmi = examForm.querySelector('[data-bmi]');
        if (bmi && height > 0 && weight > 0) {
            bmi.textContent = `${(weight / ((height / 100) ** 2)).toFixed(2)} · kategori dihitung saat disimpan`;
        } else if (bmi) {
            bmi.textContent = '— · isi tinggi dan berat badan';
        }
    });
    examForm.addEventListener('submit', () => { dirty = false; });
    window.addEventListener('beforeunload', (event) => {
        if (!dirty) return;
        event.preventDefault();
        event.returnValue = '';
    });
}

document.querySelectorAll('[data-package-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
        const inputs = [...button.closest('[data-package-group]').querySelectorAll('input[type="checkbox"]')];
        const select = inputs.some((input) => !input.checked);
        inputs.forEach((input) => { input.checked = select; });
    });
});

document.querySelector('[data-package-full]')?.addEventListener('click', () => {
    document.querySelectorAll('[data-package-group] input[type="checkbox"]').forEach((input) => {
        input.checked = !['asma', 'ginjal'].includes(input.value);
    });
});
