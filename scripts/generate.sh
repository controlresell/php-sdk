#!/usr/bin/env bash
# Regenerates the client from the OpenAPI document of the ControlResell public API.
#
#   scripts/generate.sh [spec]   # a file or a URL; default: https://api.controlresell.com/api/public/openapi.json
#
# The `Idempotency-Key` header parameter is dropped before generation (scripts/prepare-spec.php): the client sets it.
# Writes src/Generated and .spec-sha256 (of the document as served). The document itself is not committed.
# Needs Java 11+ and PHP 8.1+.
set -euo pipefail

GENERATOR_VERSION=7.26.0
SPEC="${1:-https://api.controlresell.com/api/public/openapi.json}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

JAR="$ROOT/.generator/openapi-generator-cli-$GENERATOR_VERSION.jar"
if [ ! -f "$JAR" ]; then
  mkdir -p "$ROOT/.generator"
  curl -fsSL -o "$JAR" \
    "https://repo1.maven.org/maven2/org/openapitools/openapi-generator-cli/$GENERATOR_VERSION/openapi-generator-cli-$GENERATOR_VERSION.jar"
fi

case "$SPEC" in
  http://*|https://*) curl -fsSL -o "$WORK/openapi.json" "$SPEC" ;;
  *) cp "$SPEC" "$WORK/openapi.json" ;;
esac
php "$ROOT/scripts/prepare-spec.php" "$WORK/openapi.json" "$WORK/prepared.json"

java -jar "$JAR" generate \
  -i "$WORK/prepared.json" \
  -g php \
  -o "$WORK/out" \
  --global-property=apiTests=false,modelTests=false,apiDocs=false,modelDocs=false \
  --additional-properties=invokerPackage='ControlResell\Generated',srcBasePath=src,hideGenerationTimestamp=true \
  > "$WORK/generate.log"

TARGET="$ROOT/src/Generated"
rm -rf "$TARGET"
cp -R "$WORK/out/src" "$TARGET"

shasum -a 256 "$WORK/openapi.json" | cut -d' ' -f1 > "$ROOT/.spec-sha256"
echo "Generated from $SPEC (sha256 $(cat "$ROOT/.spec-sha256"))"
