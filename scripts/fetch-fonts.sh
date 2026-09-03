#!/bin/sh
# Downloads Vazirmatn for Persian PDF rendering.
#
# Optional: without it the template falls back to loading the same family from
# Google Fonts at render time, which needs the Gotenberg container to have
# internet access. Run this once and rendering becomes fully offline.
set -e

dir="$(cd "$(dirname "$0")/.." && pwd)/templates/assets"
base="https://cdn.jsdelivr.net/npm/vazirmatn@33.0.3/fonts/webfonts"

mkdir -p "$dir"
for weight in Regular Bold; do
    echo "fetching Vazirmatn-${weight}.woff2"
    curl -fsSL "${base}/Vazirmatn-${weight}.woff2" -o "${dir}/Vazirmatn-${weight}.woff2"
done

echo "done: $dir"
ls -l "$dir"
