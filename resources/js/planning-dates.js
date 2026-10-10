const planningForm = document.querySelector('[data-planning-dates]');

if (planningForm) {
    const start = planningForm.querySelector('[name="tgl_mulai"]');
    const end = planningForm.querySelector('[name="tgl_selesai"]');
    const updateEnd = () => {
        if (!start.value) return;
        const date = new Date(`${start.value}T00:00:00Z`);
        if (Number.isNaN(date.getTime())) return;
        date.setUTCDate(date.getUTCDate() + 14);
        end.value = date.toISOString().slice(0, 10);
    };
    start.addEventListener('input', updateEnd);
    start.addEventListener('change', updateEnd);
}
