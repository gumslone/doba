/**
 * The public two-month availability calendar (§6).
 *
 * Plain DOM, no expression-evaluating framework: the §14 Content Security
 * Policy forbids 'unsafe-eval', and Alpine — like Vue's runtime compiler —
 * evaluates its templates with new Function(), so every binding silently
 * fails under that policy. Rendering imperatively keeps the strict CSP and
 * costs less code than the workarounds.
 *
 * Reads the real /api/calendar payload — per date: available, price,
 * min_stay, cta, ctd, units_left — and applies the SAME rules the server
 * enforces in AvailabilityService, so the guest is never offered a range
 * the booking engine would refuse:
 *
 *   N (nights sold) = [check_in … check_out − 1] → inventory, closed
 *   B (boundary)    = check_in (CTA, min-stay) and check_out (CTD only)
 *
 * This is a convenience layer, never the authority: every selection is
 * re-validated server-side at checkout and again, under lock, at booking
 * time. Without JavaScript the guest uses the date fields in the search
 * form and loses only the preview.
 */

const iso = (date) => {
    // Local components, never toISOString(): that converts to UTC and
    // silently shifts the date by one in any timezone east of London.
    const pad = (n) => String(n).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
};

const addDays = (date, n) => {
    const copy = new Date(date);
    copy.setDate(copy.getDate() + n);

    return copy;
};

const startOfToday = () => {
    const d = new Date();
    d.setHours(0, 0, 0, 0);

    return d;
};

class DobaCalendar {
    constructor(root) {
        this.root = root;
        this.config = JSON.parse(root.dataset.config);

        this.roomType = Number(this.config.roomType);
        this.cursor = new Date(new Date().getFullYear(), new Date().getMonth(), 1);
        this.days = {};
        this.start = null;
        this.end = null;

        this.el = {
            select: root.querySelector('[data-cal-room]'),
            prev: root.querySelector('[data-cal-prev]'),
            next: root.querySelector('[data-cal-next]'),
            months: root.querySelector('[data-cal-months]'),
            figures: root.querySelector('[data-cal-figures]'),
            message: root.querySelector('[data-cal-message]'),
            submit: root.querySelector('[data-cal-continue]'),
        };

        this.bind();
        this.load();
    }

    bind() {
        this.el.prev?.addEventListener('click', () => this.move(-1));
        this.el.next?.addEventListener('click', () => this.move(1));

        this.el.select?.addEventListener('change', (event) => {
            this.roomType = Number(event.target.value);
            this.start = this.end = null;
            this.days = {};
            this.load();
        });

        // One delegated listener rather than one per cell: the grid is
        // rebuilt on every render, and re-binding 60 buttons each time is
        // how calendars end up leaking handlers.
        this.el.months?.addEventListener('click', (event) => {
            const button = event.target.closest('[data-date]');

            if (button && !button.disabled) {
                this.pick(button.dataset.date);
            }
        });

        this.el.submit?.addEventListener('click', () => this.continueToCheckout());
    }

    async load() {
        const from = iso(this.cursor > startOfToday() ? this.cursor : startOfToday());
        const to = iso(new Date(this.cursor.getFullYear(), this.cursor.getMonth() + 2, 1));

        this.render(); // draw the frame immediately, fill it when data lands

        try {
            const response = await fetch(
                `${this.config.endpoint}?room_type=${this.roomType}&from=${from}&to=${to}`,
                { headers: { Accept: 'application/json' } },
            );

            if (!response.ok) return;

            const payload = await response.json();

            for (const day of payload.days ?? []) {
                this.days[day.date] = day;
            }
        } catch {
            // A failed fetch leaves the grid empty rather than showing
            // stale or invented availability.
        }

        this.render();
    }

    move(delta) {
        this.cursor = new Date(this.cursor.getFullYear(), this.cursor.getMonth() + delta, 1);
        this.load();
    }

    pick(key) {
        const info = this.days[key];

        if (!info || !info.available) return;

        if (!this.start || this.end || key <= this.start) {
            this.start = key;
            this.end = null;
        } else {
            this.end = key;
        }

        this.render();
    }

    /** The §6 rule set, mirrored. */
    evaluate() {
        if (!this.start) return { state: 'none' };

        const arrival = this.days[this.start];

        if (!arrival) return { state: 'none' };
        if (arrival.cta) return { state: 'cta' };
        if (!this.end) return { state: 'partial' };

        const nights = Math.round((new Date(this.end) - new Date(this.start)) / 864e5);

        if (nights > this.config.maxNights) {
            return { state: 'long', max: this.config.maxNights };
        }

        // The checkout row is a boundary: CTD only, never inventory.
        const departure = this.days[this.end];

        if (departure && departure.ctd) return { state: 'ctd' };

        let total = 0;

        for (let i = 0; i < nights; i++) {
            const night = this.days[iso(addDays(new Date(this.start), i))];

            if (!night || !night.available) return { state: 'gap' };

            total += night.price ?? 0;
        }

        // Min-stay is evaluated on the ARRIVAL date only — that is what ARI
        // and every OTA mean by it (§6).
        if (nights < (arrival.min_stay ?? 1)) {
            return { state: 'short', need: arrival.min_stay };
        }

        return { state: 'ok', nights, total, average: Math.round(total / nights) };
    }

    money(minor) {
        return new Intl.NumberFormat(this.config.locale, {
            style: 'currency',
            currency: this.config.currency,
            maximumFractionDigits: 0,
        }).format((minor ?? 0) / 100);
    }

    // ---- rendering ---------------------------------------------------
    render() {
        this.renderMonths();
        this.renderSummary();

        if (this.el.prev) {
            this.el.prev.disabled = this.cursor <= new Date(startOfToday().getFullYear(), startOfToday().getMonth(), 1);
        }
    }

    renderMonths() {
        if (!this.el.months) return;

        this.el.months.replaceChildren(
            this.monthNode(this.cursor),
            this.monthNode(new Date(this.cursor.getFullYear(), this.cursor.getMonth() + 1, 1)),
        );
    }

    monthNode(base) {
        const wrapper = document.createElement('div');
        wrapper.className = 'month';

        const title = document.createElement('h4');
        const label = new Intl.DateTimeFormat(this.config.locale, { month: 'long', year: 'numeric' }).format(base);
        title.textContent = label.charAt(0).toUpperCase() + label.slice(1);
        wrapper.append(title);

        const dow = document.createElement('div');
        dow.className = 'dow';

        for (const name of this.config.dayNames) {
            const span = document.createElement('span');
            span.textContent = name;
            dow.append(span);
        }

        wrapper.append(dow);

        const grid = document.createElement('div');
        grid.className = 'cal-grid';

        const lead = (base.getDay() + 6) % 7; // Monday-first
        const daysInMonth = new Date(base.getFullYear(), base.getMonth() + 1, 0).getDate();

        for (let i = 0; i < lead; i++) {
            const filler = document.createElement('div');
            filler.className = 'day is-empty';
            grid.append(filler);
        }

        for (let d = 1; d <= daysInMonth; d++) {
            grid.append(this.dayNode(new Date(base.getFullYear(), base.getMonth(), d), d));
        }

        wrapper.append(grid);

        return wrapper;
    }

    dayNode(date, dayNumber) {
        const key = iso(date);
        const info = this.days[key];
        const past = date < startOfToday();

        const button = document.createElement('button');
        button.type = 'button';
        button.dataset.date = key;

        const number = document.createElement('span');
        number.className = 'd';
        number.textContent = String(dayNumber);
        button.append(number);

        const classes = ['day'];

        if (past || !info) {
            classes.push('is-out');
            button.disabled = true;
        } else {
            if (!info.available) {
                classes.push('is-closed');
                button.disabled = true;
            }
            if (info.cta && info.available) classes.push('is-cta');
            if (info.available && info.units_left === 1) classes.push('is-low');
            if (key === this.start) classes.push('is-start');
            if (key === this.end) classes.push('is-end');
            if (this.start && this.end && key > this.start && key < this.end) classes.push('in-range');

            if (info.available && info.price) {
                const price = document.createElement('span');
                price.className = 'p';
                price.textContent = this.money(info.price);
                button.append(price);
            }

            button.setAttribute('aria-label', `${key}${info.available ? '' : ' — ' + this.config.strings.leg_closed}`);
        }

        button.className = classes.join(' ');

        return button;
    }

    renderSummary() {
        const result = this.evaluate();
        const strings = this.config.strings;

        if (this.el.figures) {
            this.el.figures.replaceChildren();

            if (result.state === 'ok') {
                this.el.figures.append(
                    this.figure(result.nights, strings.nights_label),
                    this.figure(this.money(result.total), strings.total),
                    this.figure(this.money(result.average), strings.avg_night),
                );
            }
        }

        if (this.el.message) {
            const messages = {
                none: strings.hint,
                partial: strings.pick_departure,
                cta: strings.cta,
                ctd: strings.ctd,
                gap: strings.gap,
                short: (strings.short ?? '').replace(':count', result.need),
                long: (strings.too_long ?? '').replace(':count', result.max),
                ok: strings.ok,
            };

            this.el.message.textContent = messages[result.state] ?? '';
            this.el.message.classList.toggle('warn', ['cta', 'ctd', 'gap', 'short', 'long'].includes(result.state));
        }

        if (this.el.submit) {
            this.el.submit.disabled = result.state !== 'ok';
        }
    }

    figure(value, label) {
        const wrapper = document.createElement('div');
        wrapper.className = 'fig';

        const strong = document.createElement('b');
        strong.textContent = String(value);

        const span = document.createElement('span');
        span.textContent = label;

        wrapper.append(strong, span);

        return wrapper;
    }

    /** Hand off to the real checkout route — the server re-validates. */
    continueToCheckout() {
        if (this.evaluate().state !== 'ok') return;

        const params = new URLSearchParams({
            room_type: String(this.roomType),
            check_in: this.start,
            check_out: this.end,
            adults: String(this.config.adults ?? 2),
            children: '0',
        });

        window.location = `${this.config.checkoutUrl}?${params}`;
    }
}

export default function initCalendars() {
    document.querySelectorAll('[data-doba-calendar]').forEach((root) => new DobaCalendar(root));
}
