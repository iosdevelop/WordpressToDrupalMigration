#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WP_DIR="${WP_DIR:-$REPO_ROOT/wordpress}"
LEGACY_BASE_URL="${LEGACY_BASE_URL:-https://legacy.example.com}"

if ! command -v ddev >/dev/null 2>&1; then
  echo "Error: ddev is required." >&2
  exit 1
fi

if [ ! -d "$WP_DIR/.ddev" ]; then
  echo "Error: expected WordPress DDEV project at $WP_DIR" >&2
  exit 1
fi

wp_cli() {
  (cd "$WP_DIR" && ddev wp "$@" </dev/null)
}

if ! (cd "$WP_DIR" && ddev describe >/dev/null 2>&1); then
  echo "Error: WordPress DDEV project is not running. Start it with: (cd wordpress && ddev start)" >&2
  exit 1
fi

if ! wp_cli core is-installed >/dev/null 2>&1; then
  echo "Error: WordPress is not installed. See README bootstrap steps." >&2
  exit 1
fi

build_html() {
  local title="$1"
  local slug="$2"
  local section="$3"
  local index="$4"

  cat <<HTML
<h2>${title}</h2>
<p>This deterministic prototype page belongs to the ${section} section and fixture index ${index}.</p>
<p>Legacy references include <a href="${LEGACY_BASE_URL}/about/company-overview/">About</a> and <a href="${LEGACY_BASE_URL}/services/strategy-workshop/">Services</a>.</p>
<p><img src="${LEGACY_BASE_URL}/wp-content/uploads/2025/01/${slug}.jpg" alt="${title}" /></p>
<p>This page helps validate title body metadata redirects and future consolidation logic.</p>
HTML
}

get_page_id_by_slug() {
  local slug="$1"
  wp_cli post list --post_type=page --name="$slug" --posts_per_page=1 --field=ID --format=ids
}

PAGE_DEFINITIONS=$(cat <<'DATA'
company|Company|about||1
about-company-overview|Company Overview|about||1
about-leadership|Leadership|about||1
about-history|History|about||1
about-values|Values|about||1
about-awards|Awards|about||1
about-careers|Careers|about||1
about-newsroom|Newsroom|about||1
service-strategy-workshop|Strategy Workshop|services||1
service-content-audit|Content Audit|services||1
service-information-architecture|Information Architecture|services||1
service-ux-writing|UX Writing|services||1
service-seo-remediation|SEO Remediation|services||1
service-analytics-implementation|Analytics Implementation|services||1
service-accessibility-audit|Accessibility Audit|services||1
service-drupal-build|Drupal Build|services||1
service-wordpress-decommission|WordPress Decommission|services||1
service-support-retainer|Support Retainer|services||1
industry-healthcare|Healthcare|industries||1
industry-higher-ed|Higher Education|industries||1
industry-financial-services|Financial Services|industries||1
industry-nonprofit|Nonprofit|industries||1
industry-public-sector|Public Sector|industries||1
industry-manufacturing|Manufacturing|industries||1
industry-technology|Technology|industries||1
industry-hospitality|Hospitality|industries||1
resource-case-study-hospital|Case Study Hospital|resources||1
resource-case-study-university|Case Study University|resources||1
resource-case-study-bank|Case Study Bank|resources||1
resource-webinar-drupal-migration|Webinar Drupal Migration|resources||1
resource-checklist-content-inventory|Checklist Content Inventory|resources||1
resource-guide-redirect-strategy|Guide Redirect Strategy|resources||1
resource-template-ia-worksheet|Template IA Worksheet|resources||1
resource-faq-migration|FAQ Migration|resources||0
legal-privacy-policy|Privacy Policy|legal||1
legal-terms-of-use|Terms Of Use|legal||1
contact-sales|Contact Sales|contact||1
contact-support|Contact Support|contact||1
locations-phoenix|Location Phoenix|locations||1
locations-denver|Location Denver|locations||1
enterprise-governance-and-compliance-program-roadmap-2026|Enterprise Governance And Compliance Program Roadmap 2026 For Multi Region Publishing Teams|about||1
company-team-directory|Company Team Directory|about|company|1
DATA
)

declare -A page_ids
created=0
skipped=0
definition_count=0

while IFS='|' read -r slug title section parent_slug has_description; do
  [ -z "$slug" ] && continue
  definition_count=$((definition_count + 1))

  existing_id="$(get_page_id_by_slug "$slug")"
  if [ -n "$existing_id" ]; then
    page_ids["$slug"]="$existing_id"
    skipped=$((skipped + 1))
    echo "Skip: $slug already exists (ID: $existing_id)"
    continue
  fi

  parent_id=0
  if [ -n "$parent_slug" ]; then
    parent_id="${page_ids[$parent_slug]:-}"
    if [ -z "$parent_id" ]; then
      parent_id="$(get_page_id_by_slug "$parent_slug")"
      if [ -z "$parent_id" ]; then
        echo "Warning: parent slug '$parent_slug' missing for '$slug'. Creating as top-level page."
        parent_id=0
      fi
    fi
  fi

  html_content="$(build_html "$title" "$slug" "$section" "$definition_count")"

  page_id="$(wp_cli post create \
    --post_type=page \
    --post_status=publish \
    --post_title="$title" \
    --post_name="$slug" \
    --post_parent="$parent_id" \
    --post_content="$html_content" \
    --porcelain)"

  page_ids["$slug"]="$page_id"

  legacy_url="${LEGACY_BASE_URL}/${section}/${slug}/"
  if [ -n "$parent_slug" ]; then
    legacy_url="${LEGACY_BASE_URL}/${parent_slug}/${slug}/"
  fi

  wp_cli post meta update "$page_id" legacy_url "$legacy_url" >/dev/null
  wp_cli post meta update "$page_id" seo_title "${title} | Legacy Prototype" >/dev/null

  if [ "$has_description" = "1" ]; then
    wp_cli post meta update "$page_id" seo_description "Prototype SEO description for ${title}." >/dev/null
  fi

  created=$((created + 1))
  echo "Created: $slug (ID: $page_id)"
done <<<"$PAGE_DEFINITIONS"

total_with_legacy="$(wp_cli post list --post_type=page --meta_key=legacy_url --post_status=publish --format=count)"

echo
echo "Fixture generation complete."
echo "Definitions processed: $definition_count"
echo "Created this run: $created"
echo "Skipped this run: $skipped"
echo "Published pages with legacy_url meta: $total_with_legacy"
