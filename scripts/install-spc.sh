#!/usr/bin/env bash
#
# Install static-php-cli to ~/.voidlux/spc/
# https://github.com/crazywhalecc/static-php-cli
#

set -euo pipefail

SPC_DIR="${HOME}/.voidlux/spc"
SPC_VERSION="2.3.1"

echo "=== Installing static-php-cli ==="
echo "Directory: ${SPC_DIR}"
echo "Version: ${SPC_VERSION}"
echo ""

# Check prerequisites
for cmd in git php composer; do
    if ! command -v "$cmd" &> /dev/null; then
        echo "Error: $cmd is required but not installed."
        exit 1
    fi
done

# Create directory
mkdir -p "${SPC_DIR}"

if [ -d "${SPC_DIR}/.git" ]; then
    echo "Updating existing installation..."
    cd "${SPC_DIR}"
    git pull
else
    echo "Cloning static-php-cli..."
    git clone --depth 1 https://github.com/crazywhalecc/static-php-cli.git "${SPC_DIR}"
    cd "${SPC_DIR}"
fi

# Install dependencies
echo "Installing composer dependencies..."
composer install --no-dev --no-interaction

# Verify
if [ -f "${SPC_DIR}/bin/spc" ]; then
    echo ""
    echo "=== Installation Complete ==="
    echo "SPC binary: ${SPC_DIR}/bin/spc"
    echo ""
    php "${SPC_DIR}/bin/spc" --version || true
else
    echo ""
    echo "Error: Installation seems to have failed. bin/spc not found."
    exit 1
fi
