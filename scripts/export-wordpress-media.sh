#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WP_DIR="${WP_DIR:-$REPO_ROOT/wordpress}"
DATA_DIR="$REPO_ROOT/data"
MEDIA_FILE="$DATA_DIR/wordpress-media.csv"

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

derive_dest_uri() {
  local file_url="$1"
  local filename
  filename="$(basename "$file_url")"
  printf 'public://wp-migration/%s' "$filename"
}

echo "Exporting media attachments..."
printf 'ID,post_title,file_name,file_url,dest_uri,alt_text,post_mime_type\n' >"$MEDIA_FILE"
media_count=0

ids="$(wp_cli post list --post_type=attachment --post_status=inherit --field=ID --format=ids)"

if [ -z "$ids" ]; then
  echo "No media attachments found."
  echo "Media exported: 0 -> $MEDIA_FILE"
  exit 0
fi

for id in $ids; do
  post_title="$(wp_cli post get "$id" --field=post_title)"
  post_name="$(wp_cli post get "$id" --field=post_name)"
  file_url="$(wp_cli post get "$id" --field=guid)"
  post_mime_type="$(wp_cli post get "$id" --field=post_mime_type)"
  alt_text="$(wp_cli post meta get "$id" _wp_attachment_image_alt 2>/dev/null || true)"

  file_name="$(basename "$file_url")"
  dest_uri="$(derive_dest_uri "$file_url")"

  post_title="$(sanitize_field "$post_title")"
  post_name="$(sanitize_field "$post_name")"
  file_url="$(sanitize_field "$file_url")"
  file_name="$(sanitize_field "$file_name")"
  dest_uri="$(sanitize_field "$dest_uri")"
  alt_text="$(sanitize_field "$alt_text")"
  post_mime_type="$(sanitize_field "$post_mime_type")"

  printf '%s,%s,%s,%s,%s,%s,%s\n' \
    "$id" \
    "$post_title" \
    "$file_name" \
    "$file_url" \
    "$dest_uri" \
    "$alt_text" \
    "$post_mime_type" >>"$MEDIA_FILE"

  media_count=$((media_count + 1))
done

echo "Media exported: $media_count -> $MEDIA_FILE"
