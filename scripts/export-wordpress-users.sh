#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WP_DIR="${WP_DIR:-$REPO_ROOT/wordpress}"
DATA_DIR="$REPO_ROOT/data"
USERS_FILE="$DATA_DIR/wordpress-users.csv"

if ! command -v ddev >/dev/null 2>&1; then
  echo "Error: ddev is required." >&2
  exit 1
fi

if [ ! -d "$WP_DIR/.ddev" ]; then
  echo "Error: expected WordPress DDEV project at $WP_DIR" >&2
  exit 1
fi

wp_cli() {
  (cd "$WP_DIR" && ddev wp "$@")
}

if ! (cd "$WP_DIR" && ddev describe >/dev/null 2>&1); then
  echo "Error: WordPress DDEV project is not running. Start it with: (cd wordpress && ddev start)" >&2
  exit 1
fi

if ! wp_cli core is-installed >/dev/null 2>&1; then
  echo "Error: WordPress is not installed. See README bootstrap steps." >&2
  exit 1
fi

mkdir -p "$DATA_DIR"

sanitize_field() {
  local value="${1:-}"
  value="${value//$'\n'/ }"
  value="${value//$'\r'/ }"
  value="${value//,/; }"
  value="${value//\"/}"
  printf '%s' "$value"
}

echo "Exporting users..."
printf 'ID,user_login,user_email,display_name,user_registered,roles\n' >"$USERS_FILE"
user_count=0

while IFS=$'\t' read -r id login email display_name registered roles; do
  [ "$id" = "ID" ] && continue
  login="$(sanitize_field "$login")"
  email="$(sanitize_field "$email")"
  display_name="$(sanitize_field "$display_name")"
  registered="$(sanitize_field "$registered")"
  roles="$(sanitize_field "$roles")"
  printf '%s,%s,%s,%s,%s,%s\n' "$id" "$login" "$email" "$display_name" "$registered" "$roles" >>"$USERS_FILE"
  user_count=$((user_count + 1))
done < <(wp_cli user list --fields=ID,user_login,user_email,display_name,user_registered,roles --format=csv | tail -n +2 | tr ',' '\t')

echo "Users exported: $user_count -> $USERS_FILE"
