#!/usr/bin/env bash
# Genera dist/qhatuq.zip listo para subir en Plugins > Añadir nuevo > Subir plugin.
# La carpeta build/qhatuq/ queda con el plugin sin comprimir (la usa GitHub Actions).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BUILD="$ROOT/build"
DIST="$ROOT/dist"
DEST="$BUILD/qhatuq"

rm -rf "$BUILD" "$DIST"
mkdir -p "$DEST" "$DIST"

cp -r "$ROOT/qhatuq.php" "$ROOT/includes" "$ROOT/admin" "$ROOT/assets" "$ROOT/composer.json" "$DEST/"
[ -f "$ROOT/composer.lock" ] && cp "$ROOT/composer.lock" "$DEST/"
[ -f "$ROOT/README.md" ] && cp "$ROOT/README.md" "$DEST/"

composer install --working-dir="$DEST" --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress

# Quita lo que no hace falta en producción.
find "$DEST/vendor" -type d \( -name .git -o -name tests -o -name Tests -o -name docs -o -name examples -o -name .github \) -prune -exec rm -rf {} +

(cd "$BUILD" && zip -qr "$DIST/qhatuq.zip" qhatuq)
echo "Listo: $DIST/qhatuq.zip ($(du -h "$DIST/qhatuq.zip" | cut -f1))"
