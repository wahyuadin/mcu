const visitSelect = document.getElementById('patient-visit');
const packageSelect = document.getElementById('patient-package');

if (visitSelect && packageSelect) {
    const showMasterPackages = () => {
        for (const option of packageSelect.options) {
            if (option.value) {
                option.hidden = false;
                option.disabled = false;
            }
        }
    };
    visitSelect.addEventListener('change', showMasterPackages);
    showMasterPackages();
}
