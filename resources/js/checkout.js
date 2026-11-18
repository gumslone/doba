/*
 * The checkout's running total.
 *
 * The summary used to be printed once, for the cheapest rate with nothing
 * added, and never touched again: a guest who chose the flexible rate and
 * ticked breakfast pressed "Complete booking" under a figure well below
 * what they were then charged. Every amount here comes from the server —
 * each rate and each extra carries its own total for this exact stay — so
 * this adds, and never prices. The server prices again on submit.
 */
const minorUnits = (value) => {
    const n = Number.parseInt(value ?? '', 10);

    return Number.isFinite(n) ? n : 0;
};

const formatter = (locale, currency) => (minor) => {
    // Whole amounts without decimals, like Money::format on the server.
    const digits = minor % 100 === 0 ? 0 : 2;

    try {
        return new Intl.NumberFormat(locale, {
            style: 'currency',
            currency,
            minimumFractionDigits: digits,
            maximumFractionDigits: digits,
        }).format(minor / 100);
    } catch {
        return `${(minor / 100).toFixed(digits)} ${currency}`;
    }
};

export default function initCheckout() {
    const summary = document.querySelector('[data-checkout-summary]');

    if (!summary) {
        return;
    }

    const money = formatter(summary.dataset.locale || 'en', summary.dataset.currency || 'EUR');
    const fixed = minorUnits(summary.dataset.fixed);
    const plans = [...document.querySelectorAll('input[name="rate_plan"][data-plan-total]')];
    const extras = [...document.querySelectorAll('input[data-extra-total]')];
    const extrasRow = summary.querySelector('[data-sum-extras-row]');

    const update = () => {
        const chosen = plans.find((input) => input.checked);
        const room = chosen ? minorUnits(chosen.dataset.planTotal) : minorUnits(summary.dataset.room);
        const added = extras
            .filter((input) => input.checked)
            .reduce((sum, input) => sum + minorUnits(input.dataset.extraTotal), 0);

        summary.querySelectorAll('[data-sum-room]').forEach((el) => {
            el.textContent = money(room);
        });

        if (extrasRow) {
            extrasRow.hidden = added === 0;
            extrasRow.querySelectorAll('[data-sum-extras]').forEach((el) => {
                el.textContent = money(added);
            });
        }

        document.querySelectorAll('[data-sum-total]').forEach((el) => {
            el.textContent = money(room + added + fixed);
        });

        // The highlighted card follows the choice.
        plans.forEach((input) => input.closest('.plan')?.classList.toggle('on', input.checked));
    };

    [...plans, ...extras].forEach((input) => input.addEventListener('change', update));

    // A browser restoring the form after "back" keeps its ticks.
    update();
}
