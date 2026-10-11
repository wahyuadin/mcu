export function evaluateLaboratory(config, answer) {
    if (answer == null || String(answer).trim() === '') return { status: 'Belum diperiksa', remark: '' };
    let normal = null;
    if (config.result_type === 'number' && /^[+-]?(?:\d+\.?\d*|\.\d+)(?:e[+-]?\d+)?$/i.test(String(answer).trim()) && Number.isFinite(Number(answer))) {
        const value = Number(answer);
        const min = config.min;
        const max = config.max;
        switch (config.rule) {
            case 'range': if (min != null && max != null) normal = value >= Number(min) && value <= Number(max); break;
            case 'lt': if (max != null) normal = value < Number(max); break;
            case 'lte': if (max != null) normal = value <= Number(max); break;
            case 'gt': if (min != null) normal = value > Number(min); break;
            case 'gte': if (min != null) normal = value >= Number(min); break;
        }
    } else if (config.result_type === 'text' && config.rule === 'text' && config.normal_values?.length) {
        normal = config.normal_values.some((value) => value.trim().toLowerCase() === String(answer).trim().toLowerCase());
    }
    if (normal === null) return { status: 'Tidak dapat dibandingkan', remark: '' };
    return { status: normal ? 'Normal' : 'Tidak normal', remark: (normal ? config.normal_report : config.abnormal_report) || '' };
}

export function setupLaboratoryRow(row) {
    const input = row.querySelector('[data-laboratory-answer]');
    const config = JSON.parse(row.dataset.config);
    const update = () => {
        const result = evaluateLaboratory(config, input.validity?.badInput ? 'invalid' : input.value);
        row.querySelector('[data-laboratory-status]').textContent = result.status;
        row.querySelector('[data-laboratory-remark]').textContent = result.remark;
    };
    input.addEventListener('input', update);
    update();
}

if (typeof document !== 'undefined') document.querySelectorAll('[data-laboratory-row]').forEach(setupLaboratoryRow);
