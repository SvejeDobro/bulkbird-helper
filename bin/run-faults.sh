#!/usr/bin/env bash
# Namjerni kvarovi (H0.81–H0.90): svaki mora srušiti provjere.
set -euo pipefail
cd "$(dirname "$0")/.."
exec "${PHP:-php}" bin/run-faults.php
