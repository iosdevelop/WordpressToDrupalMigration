#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKUP_DIR="$REPO_ROOT/backups"
TIMESTAMP="$(date +%Y%m%d_%H%M%S)"
WP_DIR="${WP_DIR:-$REPO_ROOT/wordpress}"
DRUPAL_DIR="${DRUPAL_DIR:-$REPO_ROOT/drupal}"

if ! command -v ddev >/dev/null 2>&1; then
  echo "Error: ddev is required." >&2
  exit 1
fi

mkdir -p "$BACKUP_DIR"

backup_project() {
  local name="$1"
  local dir="$2"
  local out="$BACKUP_DIR/${name}_${TIMESTAMP}.sql.gz"

  if [ ! -d "$dir/.ddev" ]; then
    echo "Warning: no DDEV project at $dir — skipping $name backup." >&2
    return
  fi

  if ! (cd "$dir" && ddev describe >/dev/null 2>&1); then
    echo "Warning: $name DDEV project is not running — skipping backup." >&2
    return
  fi

  echo "Backing up $name database..."
  (cd "$dir" && ddev export-db --file="$out")
  echo "  -> $out"
}

backup_project "wordpress" "$WP_DIR"
backup_project "drupal"    "$DRUPAL_DIR"

echo
echo "Backups written to: $BACKUP_DIR"
echo "Timestamp: $TIMESTAMP"
