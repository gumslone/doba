import 'trix';
import initCalendars from './calendar';
import initDisclosures from './disclosures';
import initGrid from './grid';
import { initStylePresets } from './styles-admin';

/*
 * Plain DOM, deliberately.
 *
 * §1 chose "server-rendered Blade + Alpine". Alpine evaluates its templates
 * with new Function(), which the §14 Content Security Policy forbids
 * ('unsafe-eval' is not granted) — so every Alpine binding fails silently
 * under our own security headers. Rather than weaken the CSP for a site
 * with exactly three interactive pieces, those pieces are written against
 * the DOM directly. The security posture wins; the code is smaller.
 */
const boot = () => {
    initCalendars();
    initDisclosures();
    initGrid();
    initStylePresets();
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
} else {
    boot();
}
