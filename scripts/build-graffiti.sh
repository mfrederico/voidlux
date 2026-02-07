#!/usr/bin/env bash
#
# Compile the VoidLux graffiti wall into a static binary.
#

set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
BUILD_DIR="${PROJECT_DIR}/build"
OUTPUT="${BUILD_DIR}/graffiti-wall"

echo "=== Building VoidLux Graffiti Wall Binary ==="
echo "Project: ${PROJECT_DIR}"
echo "Output:  ${OUTPUT}"
echo ""

# Ensure spc is installed
if [ ! -f "${HOME}/.voidlux/spc/bin/spc" ]; then
    echo "static-php-cli not found. Installing..."
    bash "${PROJECT_DIR}/scripts/install-spc.sh"
fi

# Run the compiler
cd "${PROJECT_DIR}"
php bin/voidlux compile "${PROJECT_DIR}" \
    --output="${OUTPUT}" \
    --entry-point="bin/voidlux" \
    --app-name="graffiti-wall"

echo ""
echo "Done! Run: ${OUTPUT} demo --http-port=8080"
