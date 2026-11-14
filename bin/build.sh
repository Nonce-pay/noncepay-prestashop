#!/bin/bash
# Builds dist/noncepay.zip: the noncepay/ module folder at the zip root, as Back Office > Modules > Upload expects.
set -euo pipefail
cd "$(dirname "$0")/.."
rm -rf dist && mkdir -p dist
rsync -a --exclude='.DS_Store' noncepay dist/
cp LICENSE dist/noncepay/LICENSE
(cd dist && zip -qr noncepay.zip noncepay)
rm -rf dist/noncepay
echo "dist/noncepay.zip"
