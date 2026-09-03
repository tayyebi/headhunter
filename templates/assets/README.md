# templates/assets

Font files for PDF rendering. Populated by `./scripts/fetch-fonts.sh`, and
gitignored because they are binaries.

If `Vazirmatn-Regular.woff2` and `Vazirmatn-Bold.woff2` are both here, the resume
template embeds them and renders with no network access. If either is missing it
falls back to loading the same family from Google Fonts, which requires the
Gotenberg container to reach the internet.
