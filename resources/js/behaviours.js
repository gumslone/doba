/*
 * The three things this site ever did with an inline event handler,
 * done once here instead — so the Content Security Policy can stop
 * granting 'unsafe-inline' to scripts (§14).
 *
 *   <form data-confirm="Really?">         asks before submitting
 *   <select data-submit-on-change>        submits its form on change
 *   <input data-select-on-click readonly> selects its text on click
 *
 * Delegated on the document, so markup rendered later still works and
 * there is nothing to initialise per element.
 */
export default function initBehaviours() {
    document.addEventListener('submit', (event) => {
        const form = event.target.closest('form[data-confirm]');

        if (form && !window.confirm(form.dataset.confirm)) {
            event.preventDefault();
        }
    });

    document.addEventListener('change', (event) => {
        const field = event.target.closest('[data-submit-on-change]');

        if (field && field.form) {
            field.form.requestSubmit();
        }
    });

    document.addEventListener('click', (event) => {
        const input = event.target.closest('[data-select-on-click]');

        if (input && typeof input.select === 'function') {
            input.select();
        }
    });
}
