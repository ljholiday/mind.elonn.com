#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

for test_file in "$SCRIPT_DIR"/tests/*-test.php; do
    [ -f "$test_file" ] || continue
    php "$test_file"
done
