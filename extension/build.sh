#!/usr/bin/env bash
# Bundle the extension into a zip for sideloading or store submission.
#
# Usage:  ./build.sh           → ./kastip-extension-<version>.zip
#         ./build.sh -o /path  → custom output

set -euo pipefail
cd "$(dirname "$0")"

VERSION=$(jq -r .version manifest.json)
OUT="${1:-./kastip-extension-${VERSION}.zip}"

# Clean any prior build
rm -f "$OUT"

# Zip everything except dotfiles, build artefacts, the script itself
zip -qr "$OUT" . \
  -x 'build.sh' '*.DS_Store' '*.zip' '.git/*' '.gitignore' 'README.md'

ls -la "$OUT"
echo
echo "Bundled: $OUT"
echo "Sideload: chrome://extensions → Developer mode → Load unpacked → select extension/ dir"
echo "Submit:   Chrome Web Store dashboard, upload this zip"
