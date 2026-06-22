#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DRUPAL_DIR="$ROOT_DIR/drupal"
ARCHIVE="${1:-$ROOT_DIR/data/config-ivmf-sites-syr-edu-2026-06-22-15-54.tar.gz}"
IMPORT_DIR="$DRUPAL_DIR/.sandbox-structure-import"

if [[ ! -f "$ARCHIVE" ]]; then
  echo "Configuration archive not found: $ARCHIVE" >&2
  exit 1
fi

# Deliberately limited to portable content structure. Do not add environment,
# authentication, permissions, storage, mail, caching, or theme configuration.
CONFIG_FILES=(
  node.type.faq.yml
  node.type.landing_page.yml
  node.type.people.yml
  node.type.program_page.yml
  field.storage.node.field_name.yml
  field.storage.node.field_order.yml
  field.storage.node.field_program_year.yml
  field.storage.node.field_title.yml
  field.field.node.faq.body.yml
  field.field.node.faq.field_order.yml
  field.field.node.faq.field_title.yml
  field.field.node.landing_page.body.yml
  field.field.node.people.field_name.yml
  field.field.node.people.field_title.yml
  field.field.node.program_page.body.yml
  field.field.node.program_page.field_program_year.yml
  core.entity_form_display.node.faq.default.yml
  core.entity_form_display.node.landing_page.default.yml
  core.entity_form_display.node.people.default.yml
  core.entity_form_display.node.program_page.default.yml
  core.entity_view_display.node.faq.default.yml
  core.entity_view_display.node.faq.teaser.yml
  core.entity_view_display.node.landing_page.default.yml
  core.entity_view_display.node.landing_page.teaser.yml
  core.entity_view_display.node.people.default.yml
  core.entity_view_display.node.program_page.default.yml
  core.entity_view_display.node.program_page.teaser.yml
)

cleanup() {
  rm -rf "$IMPORT_DIR"
}
trap cleanup EXIT

rm -rf "$IMPORT_DIR"
mkdir -p "$IMPORT_DIR"

for config_file in "${CONFIG_FILES[@]}"; do
  if ! tar -xOf "$ARCHIVE" "$config_file" > "$IMPORT_DIR/$config_file"; then
    echo "Required configuration is missing from the archive: $config_file" >&2
    exit 1
  fi
done

cd "$DRUPAL_DIR"
ddev drush config:import \
  --partial \
  --source=/var/www/html/.sandbox-structure-import \
  --yes
ddev drush cache:rebuild

echo "Imported ${#CONFIG_FILES[@]} portable content-structure records from $(basename "$ARCHIVE")."
