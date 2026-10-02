#!/usr/bin/env bash
# Genera build/qhatuq.zip listo para subir en Plugins > Añadir nuevo > Subir plugin.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BUILD="$ROOT/build"
DEST="$BUILD/qhatuq"

rm -rf "$BUILD"
mkdir -p "$DEST"

cp -r "$ROOT/qhatuq.php" "$ROOT/includes" "$ROOT/admin" "$ROOT/assets" "$ROOT/composer.json" "$DEST/"
[ -f "$ROOT/composer.lock" ] && cp "$ROOT/composer.lock" "$DEST/"
[ -f "$ROOT/README.md" ] && cp "$ROOT/README.md" "$DEST/"

composer install --working-dir="$DEST" --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress

# Quita lo que no hace falta en producción.
find "$DEST/vendor" -type d \( -name .git -o -name tests -o -name Tests -o -name docs -o -name examples -o -name .github \) -prune -exec rm -rf {} +

(cd "$BUILD" && zip -qr qhatuq.zip qhatuq)
echo "Listo: $BUILD/qhatuq.zip ($(du -h "$BUILD/qhatuq.zip" | cut -f1))"
