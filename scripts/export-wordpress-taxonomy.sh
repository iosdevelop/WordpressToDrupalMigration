#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WP_DIR="${WP_DIR:-$REPO_ROOT/wordpress}"
DATA_DIR="$REPO_ROOT/data"
CATEGORIES_FILE="$DATA_DIR/wordpress-categories.csv"
TAGS_FILE="$DATA_DIR/wordpress-tags.csv"

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

# Export categories.
echo "Exporting categories..."
printf 'term_id,name,slug,description,parent_id\n' >"$CATEGORIES_FILE"
cat_count=0

while IFS=$'\t' read -r term_id name slug description parent; do
  [ "$term_id" = "term_id" ] && continue
  name="$(sanitize_field "$name")"
  slug="$(sanitize_field "$slug")"
  description="$(sanitize_field "$description")"
  printf '%s,%s,%s,%s,%s\n' "$term_id" "$name" "$slug" "$description" "$parent" >>"$CATEGORIES_FILE"
  cat_count=$((cat_count + 1))
done < <(wp_cli term list category --fields=term_id,name,slug,description,parent --format=csv | tail -n +2 | tr ',' '\t')

echo "Categories exported: $cat_count -> $CATEGORIES_FILE"

# Export tags.
echo "Exporting tags..."
printf 'term_id,name,slug,description\n' >"$TAGS_FILE"
tag_count=0

while IFS=$'\t' read -r term_id name slug description; do
  [ "$term_id" = "term_id" ] && continue
  name="$(sanitize_field "$name")"
  slug="$(sanitize_field "$slug")"
  description="$(sanitize_field "$description")"
  printf '%s,%s,%s,%s\n' "$term_id" "$name" "$slug" "$description" >>"$TAGS_FILE"
  tag_count=$((tag_count + 1))
done < <(wp_cli term list post_tag --fields=term_id,name,slug,description --format=csv | tail -n +2 | tr ',' '\t')

echo "Tags exported: $tag_count -> $TAGS_FILE"
