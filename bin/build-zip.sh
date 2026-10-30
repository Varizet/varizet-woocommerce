#!/usr/bin/env bash
# Builds dist/varizet-woocommerce-<version>.zip with only the runtime files (no dev tooling,
# tests, vendor or Docker files). The zip contains a single varizet-woocommerce/ folder, as
# wordpress.org and «افزودن افزونه → بارگذاری» expect.
#   bin/build-zip.sh            # version from the plugin header
#   bin/build-zip.sh --check    # also verifies header, readme.txt and CHANGELOG versions agree
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SLUG="varizet-woocommerce"
VERSION="$(sed -n 's/^ \* Version: *//p' "$ROOT/$SLUG.php" | tr -d '[:space:]')"
CONST="$(sed -n "s/^define( 'VARIZET_WC_VERSION', '\(.*\)' );/\1/p" "$ROOT/$SLUG.php")"
STABLE="$(sed -n 's/^Stable tag: *//p' "$ROOT/readme.txt" | tr -d '[:space:]')"

[ -n "$VERSION" ] || { echo "no Version in plugin header" >&2; exit 1; }
if [ "$VERSION" != "$CONST" ] || [ "$VERSION" != "$STABLE" ]; then
  echo "version mismatch: header=$VERSION const=$CONST readme Stable tag=$STABLE" >&2
  exit 1
fi
grep -q "^## \[$VERSION\]" "$ROOT/CHANGELOG.md" || { echo "CHANGELOG.md has no [$VERSION] entry" >&2; exit 1; }
[ -f "$ROOT/languages/$SLUG-fa_IR.mo" ] || { echo "languages/$SLUG-fa_IR.mo missing (see README: translations)" >&2; exit 1; }

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
DEST="$STAGE/$SLUG"
mkdir -p "$DEST"

# Runtime allowlist.
for item in "$SLUG.php" uninstall.php readme.txt CHANGELOG.md src templates assets languages; do
  cp -R "$ROOT/$item" "$DEST/"
done
cp "$ROOT/assets/fonts/OFL.txt" "$DEST/assets/fonts/OFL.txt"
find "$DEST" \( -name '.DS_Store' -o -name '*.map' -o -name '.gitkeep' \) -delete

# Every PHP file must lint before it ships (Docker fallback when php is not installed).
if command -v php >/dev/null 2>&1; then
  find "$DEST" -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
else
  docker run --rm -v "$DEST":/p -w /p php:8.2-cli sh -c 'find . -name "*.php" -print0 | xargs -0 -n1 php -l >/dev/null'
fi

mkdir -p "$ROOT/dist"
ZIP="$ROOT/dist/$SLUG-$VERSION.zip"
rm -f "$ZIP"
(cd "$STAGE" && zip -qr -X "$ZIP" "$SLUG")
echo "built $ZIP ($(du -h "$ZIP" | cut -f1), $(unzip -l "$ZIP" | tail -1 | awk '{print $2}') files)"
(cd "$ROOT/dist" && shasum -a 256 "$(basename "$ZIP")" > "$(basename "$ZIP").sha256")
