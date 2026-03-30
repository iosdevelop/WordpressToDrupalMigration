#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WP_DIR="${WP_DIR:-$REPO_ROOT/wordpress}"
DATA_DIR="$REPO_ROOT/data"
EXPORT_FILE="$DATA_DIR/wordpress-pages.csv"

if ! command -v ddev >/dev/null 2>&1; then
  echo "Error: ddev is required." >&2
  exit 1
fi

if [ ! -d "$WP_DIR/.ddev" ]; then
  echo "Error: expected WordPress DDEV project at $WP_DIR" >&2
  exit 1
fi

mkdir -p "$DATA_DIR"

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

sanitize_field() {
  local value="${1:-}"
  value="${value//$'\n'/ }"
  value="${value//$'\r'/ }"
  value="${value//,/; }"
  value="${value//\"/}" 
  printf '%s' "$value"
}

suggest_target_url() {
  local slug="$1"

  case "$slug" in
    company|about-*)
      printf '/about'
      ;;
    service-*)
      printf '/services'
      ;;
    industry-*)
      printf '/industries'
      ;;
    resource-*)
      printf '/resources'
      ;;
    legal-*)
      printf '/legal'
      ;;
    contact-*)
      printf '/contact'
      ;;
    locations-*)
      printf '/contact/locations'
      ;;
    enterprise-governance-and-compliance-program-roadmap-2026)
      printf '/about/governance'
      ;;
    *)
      printf '/content-hub'
      ;;
  esac
}

printf 'old_url,title,slug,legacy_url,seo_title,seo_description,suggested_target_url,content_html\n' >"$EXPORT_FILE"

ids="$(wp_cli post list --post_type=page --post_status=publish --meta_key=legacy_url --orderby=ID --order=ASC --field=ID --format=ids)"

if [ -z "$ids" ]; then
  echo "No published pages with legacy_url meta found. Run scripts/create-mock-pages.sh first." >&2
  exit 1
fi

count=0
for id in $ids; do
  title="$(wp_cli post get "$id" --field=post_title)"
  slug="$(wp_cli post get "$id" --field=post_name)"
  content_html="$(wp_cli post get "$id" --field=post_content)"
  legacy_url="$(wp_cli post meta get "$id" legacy_url 2>/dev/null || true)"
  seo_title="$(wp_cli post meta get "$id" seo_title 2>/dev/null || true)"
  seo_description="$(wp_cli post meta get "$id" seo_description 2>/dev/null || true)"

  if [ -z "$legacy_url" ]; then
    legacy_url="$(wp_cli post url "$id" 2>/dev/null || true)"
  fi

  old_url="$legacy_url"
  suggested_target_url="$(suggest_target_url "$slug")"

  old_url="$(sanitize_field "$old_url")"
  title="$(sanitize_field "$title")"
  slug="$(sanitize_field "$slug")"
  legacy_url="$(sanitize_field "$legacy_url")"
  seo_title="$(sanitize_field "$seo_title")"
  seo_description="$(sanitize_field "$seo_description")"
  suggested_target_url="$(sanitize_field "$suggested_target_url")"
  content_html="$(sanitize_field "$content_html")"

  printf '%s,%s,%s,%s,%s,%s,%s,%s\n' \
    "$old_url" \
    "$title" \
    "$slug" \
    "$legacy_url" \
    "$seo_title" \
    "$seo_description" \
    "$suggested_target_url" \
    "$content_html" >>"$EXPORT_FILE"

  count=$((count + 1))
done

echo "Export complete: $EXPORT_FILE"
echo "Rows exported: $count"
