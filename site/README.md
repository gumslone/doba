# The landing page

Static, no build step. `index.html` (English) and `de/index.html` (German)
share `assets/site.css` and `assets/calc.js`. Published to GitHub Pages by
`.github/workflows/pages.yml`, which also copies `docs/screenshots/` in as
`screenshots/` so the page and the README never show different pictures.

When a public demo is hosted, replace the Codespaces link behind the first
button in both files with its address.
