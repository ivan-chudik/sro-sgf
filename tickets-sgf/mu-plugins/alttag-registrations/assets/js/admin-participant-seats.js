document.addEventListener('click', function (event) {
    const button = event.target.closest('.alttag-participant-seats__step');
    if (!button) {
        return;
    }

    const row = button.closest('.alttag-participant-seats__row');
    const input = row ? row.querySelector('.alttag-participant-seats__count') : null;
    if (!input) {
        return;
    }

    const delta = Number.parseInt(button.dataset.delta, 10) || 0;
    const current = Number.parseInt(input.value, 10) || 0;
    const max = Number.parseInt(input.max, 10) || 99999;
    input.value = Math.min(max, Math.max(0, current + delta));
    input.dispatchEvent(new Event('change', { bubbles: true }));
});
