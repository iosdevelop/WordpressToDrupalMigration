#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DATA_DIR="$REPO_ROOT/data"
SOURCE_FILE="$DATA_DIR/wordpress-pages.csv"
MAP_FILE="$DATA_DIR/old-to-new-map.csv"
REDIRECT_FILE="$DATA_DIR/sample-redirects.csv"
MODULE_DATA_DIR="$REPO_ROOT/drupal/web/modules/custom/wp_drupal_prototype_migrate/data"

if [ ! -f "$SOURCE_FILE" ]; then
  echo "Error: $SOURCE_FILE not found. Run scripts/export-wordpress-pages.sh first." >&2
  exit 1
fi

mkdir -p "$DATA_DIR" "$MODULE_DATA_DIR"

printf 'old_url,title,slug,content_type,seo_title,seo_description,suggested_target_url,map_notes\n' >"$MAP_FILE"
printf 'legacy_url,redirect_to,status_code\n' >"$REDIRECT_FILE"

count=0

# Updated to handle new columns:
# old_url,title,slug,legacy_url,seo_title,seo_description,suggested_target_url,content_type,wp_author_login,wp_category_slug,content_html
while IFS=',' read -r old_url title slug legacy_url seo_title seo_description suggested_target_url content_type wp_author_login wp_category_slug content_html; do
  [ -z "${slug:-}" ] && continue

  printf '%s,%s,%s,%s,%s,%s,%s,%s\n' \
    "$old_url" \
    "$title" \
    "$slug" \
    "$content_type" \
    "$seo_title" \
    "$seo_description" \
    "$suggested_target_url" \
    "Data-driven consolidation from content-type-mapping.csv" >>"$MAP_FILE"

  printf '%s,%s,%s\n' \
    "$legacy_url" \
    "$suggested_target_url" \
    "301" >>"$REDIRECT_FILE"

  count=$((count + 1))
done < <(tail -n +2 "$SOURCE_FILE")

# Copy pages CSV and generated mappings into the module data directory.
cp "$SOURCE_FILE"   "$MODULE_DATA_DIR/wp-pages.csv"
cp "$MAP_FILE"      "$MODULE_DATA_DIR/old-to-new-map.csv"
cp "$REDIRECT_FILE" "$MODULE_DATA_DIR/sample-redirects.csv"

# Copy taxonomy, users, and media CSVs if they exist.
for extra in wordpress-categories wordpress-tags wordpress-users wordpress-media; do
  src="$DATA_DIR/${extra}.csv"
  if [ -f "$src" ]; then
    dest_name="${extra/wordpress-/wp-}"
    cp "$src" "$MODULE_DATA_DIR/${dest_name}.csv"
    echo "Copied: ${dest_name}.csv -> module data dir"
  fi
done

echo "Generated: $MAP_FILE"
echo "Generated: $REDIRECT_FILE"
echo "Copied migration inputs into: $MODULE_DATA_DIR"
echo "Rows processed: $count"
