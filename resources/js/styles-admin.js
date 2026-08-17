/**
 * Admin → Styles: picking a preset fills the colour inputs with its
 * palette.
 *
 * The emitted CSS lets the hotelier's own colours out-rank the preset, so
 * without this a hotelier who once set a green would switch to the
 * monochrome look and still see green — and conclude the preset is
 * broken. Filling the inputs makes the override visible and editable
 * instead of invisible and confusing.
 */
export function initStylePresets() {
    const presets = document.querySelectorAll('input[name="preset"][data-swatch]');

    if (presets.length === 0) {
        return;
    }

    const primary = document.getElementById('color_primary');
    const accent = document.getElementById('color_accent');

    presets.forEach((input) => {
        input.addEventListener('change', () => {
            const [brand, highlight] = input.dataset.swatch.split(',');

            if (primary) primary.value = brand;
            if (accent) accent.value = highlight;
        });
    });
}
