/**
 * The small interactive pieces outside the calendar:
 *
 *  - the click-to-load map (nothing is fetched from Google until asked)
 *  - the cookie notice, shown only where analytics are configured
 *  - the admin's per-locale editing tabs
 *
 * All written against the DOM because the §14 CSP forbids 'unsafe-eval',
 * which any expression-evaluating framework needs. See app.js.
 */

/** Replace a placeholder with the iframe it describes, once, on click. */
function initClickToLoad() {
    document.querySelectorAll('[data-load-frame]').forEach((button) => {
        button.addEventListener('click', () => {
            const container = button.closest('[data-frame-container]');

            if (!container) return;

            const frame = document.createElement('iframe');

            frame.src = container.dataset.frameSrc;
            frame.title = container.dataset.frameTitle ?? '';
            frame.loading = 'lazy';
            frame.referrerPolicy = 'no-referrer-when-downgrade';
            frame.allowFullscreen = true;
            frame.className = container.dataset.frameClass ?? '';

            container.replaceChildren(frame);
        });
    });
}

function initCookieNotice() {
    const notice = document.querySelector('[data-cookie-notice]');

    if (!notice) return;

    if (localStorage.getItem('doba-consent')) {
        notice.remove();

        return;
    }

    notice.hidden = false;

    notice.querySelectorAll('[data-consent]').forEach((button) => {
        button.addEventListener('click', () => {
            localStorage.setItem('doba-consent', button.dataset.consent);
            notice.remove();
        });
    });
}

/**
 * Per-locale tabs in the admin. Every locale's fields stay in the DOM so
 * one submit carries all languages — the tabs only change which panel is
 * visible.
 */
function initLocaleTabs() {
    document.querySelectorAll('[data-locale-tabs]').forEach((group) => {
        const tabs = [...group.querySelectorAll('[data-locale-tab]')];
        const panels = [...group.querySelectorAll('[data-locale-panel]')];

        const show = (locale) => {
            tabs.forEach((tab) => tab.setAttribute('aria-selected', String(tab.dataset.localeTab === locale)));
            panels.forEach((panel) => {
                panel.hidden = panel.dataset.localePanel !== locale;
            });
        };

        tabs.forEach((tab) => tab.addEventListener('click', () => show(tab.dataset.localeTab)));

        if (tabs.length) show(tabs[0].dataset.localeTab);
    });
}

export default function initDisclosures() {
    initClickToLoad();
    initCookieNotice();
    initLocaleTabs();
}
