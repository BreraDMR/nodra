#!/usr/bin/env bash
# Local performance run against a throwaway copy of the database (D09.5, docs/perf-local.md).
# Usage: scripts/perf-run.sh [requests_per_get_scenario]
# Requires: the perf API stack on 127.0.0.1:8001 pointed at the copy (see docs/perf-local.md).
set -euo pipefail
cd "$(dirname "$0")/.."
php scripts/perf-run.php "${1:-20}"
