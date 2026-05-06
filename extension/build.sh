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

# Zip everything except dotfiles, build artefacts, the script itself.
# Defensive: also exclude common archive types (in case someone scp's
# a handoff tarball into this dir by mistake) and node/cache leftovers.
zip -qr "$OUT" . \
  -x 'build.sh' '*.DS_Store' '*.zip' '*.tar.gz' '*.tgz' '*.tar' \
     '.git/*' '.gitignore' 'README.md' \
     'node_modules/*' '.cache/*' '*.swp'

ls -la "$OUT"
echo
echo "Bundled: $OUT"
echo "Sideload: chrome://extensions → Developer mode → Load unpacked → select extension/ dir"
echo "Submit:   Chrome Web Store dashboard, upload this zip"
