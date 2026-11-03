#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")"
command -v composer >/dev/null 2>&1 || { echo "Composer is required. Install Composer, then rerun this script." >&2; exit 1; }
composer install --no-dev --classmap-authoritative
cd ..
rm -f secure-pda-pdf-watermark-installable.zip
cd secure-pda-pdf-watermark/
zip -qr ../secure-pda-pdf-watermark-installable.zip secure-pda-pdf-watermark/ -x 'secure-pda-pdf-watermark/.gitignore'
echo "Created: $(pwd)/secure-pda-pdf-watermark-installable.zip"
