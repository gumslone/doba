/**
 * Drag-selection for the admin availability grid (§12).
 *
 * Dragging across cells fills the bulk-edit panel's date range and ticks
 * the room types touched — the plan's "cells are drag-selectable; a
 * bulk-edit panel then sets price, min-stay or stop-sell across the
 * selection". The panel remains usable on its own, so the grid works with
 * a keyboard and on touch without any of this.
 *
 * Plain DOM: the §14 CSP forbids 'unsafe-eval' (see app.js).
 */
export default function initGrid() {
    const grid = document.querySelector('[data-grid]');

    if (!grid) return;

    const from = document.querySelector('[data-grid-from]');
    const to = document.querySelector('[data-grid-to]');

    if (!from || !to) return;

    const cells = [...grid.querySelectorAll('[data-cell]')];
    let anchor = null;
    let dragging = false;

    const paint = (start, end) => {
        const [low, high] = start <= end ? [start, end] : [end, start];

        for (const cell of cells) {
            const inRange = cell.dataset.date >= low && cell.dataset.date <= high;
            cell.classList.toggle('ring-2', inRange);
            cell.classList.toggle('ring-neutral-900', inRange);
            cell.classList.toggle('ring-inset', inRange);
        }

        return [low, high];
    };

    const commit = (start, end) => {
        const [low, high] = paint(start, end);

        from.value = low;
        to.value = high;

        // Tick exactly the room types the drag touched, so a change meant
        // for one category cannot quietly apply to all of them.
        const touched = new Set(
            cells
                .filter((cell) => cell.dataset.date >= low && cell.dataset.date <= high && cell.dataset.dragged === '1')
                .map((cell) => cell.dataset.roomType),
        );

        if (touched.size) {
            document.querySelectorAll('[data-grid-room]').forEach((box) => {
                box.checked = touched.has(box.dataset.gridRoom);
            });
        }
    };

    grid.addEventListener('mousedown', (event) => {
        const cell = event.target.closest('[data-cell]');

        if (!cell || cell.disabled) return;

        event.preventDefault();
        dragging = true;
        anchor = cell.dataset.date;

        cells.forEach((c) => delete c.dataset.dragged);
        cell.dataset.dragged = '1';

        paint(anchor, anchor);
    });

    grid.addEventListener('mouseover', (event) => {
        if (!dragging) return;

        const cell = event.target.closest('[data-cell]');

        if (!cell) return;

        cell.dataset.dragged = '1';
        paint(anchor, cell.dataset.date);
    });

    const finish = (event) => {
        if (!dragging) return;

        dragging = false;

        const cell = event.target?.closest?.('[data-cell]');

        commit(anchor, cell ? cell.dataset.date : anchor);
    };

    grid.addEventListener('mouseup', finish);
    // A drag that ends outside the table still commits what it covered,
    // rather than leaving the selection half-applied.
    document.addEventListener('mouseup', finish);
}
