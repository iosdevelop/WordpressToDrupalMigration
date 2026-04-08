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

  local section_title
  local section_intro
  local default_blocks
  local spotlight

  case "$section" in
    about)
      section_title="About"
      section_intro="We are a digital transformation consultancy focused on content platforms, governance, and measurable outcomes."
      default_blocks=$(cat <<'HTML'
<h3>What We Stand For</h3>
<ul>
  <li>Clear content strategy tied to business goals.</li>
  <li>Practical platform decisions with sustainable governance.</li>
  <li>Execution plans that reduce migration risk and delivery friction.</li>
</ul>
HTML
)
      ;;
    services)
      section_title="Services"
      section_intro="Our service model combines discovery, architecture, implementation, and optimization for enterprise web platforms."
      default_blocks=$(cat <<'HTML'
<h3>Typical Engagement Outcomes</h3>
<ul>
  <li>Prioritized roadmap with milestones, owners, and confidence levels.</li>
  <li>Content architecture aligned to user journeys and internal operations.</li>
  <li>Delivery playbook for migration, QA, and post-launch support.</li>
</ul>
HTML
)
      ;;
    industries)
      section_title="Industries"
      section_intro="We tailor migration and platform modernization approaches to domain-specific compliance, governance, and publishing needs."
      default_blocks=$(cat <<'HTML'
<h3>How We Adapt By Sector</h3>
<ul>
  <li>Audience-first information architecture and conversion pathways.</li>
  <li>Compliance-aware workflows and content lifecycle controls.</li>
  <li>Measurement plans connected to executive reporting.</li>
</ul>
HTML
)
      ;;
    resources)
      section_title="Resources"
      section_intro="Browse practical templates, checklists, and planning guides that help teams execute migrations with less risk."
      default_blocks=$(cat <<'HTML'
<h3>Included In Most Resource Kits</h3>
<ul>
  <li>Scoping worksheet for content inventory and destination modeling.</li>
  <li>Redirect mapping starter matrix and QA checklist.</li>
  <li>Stakeholder communication templates for phased rollouts.</li>
</ul>
HTML
)
      ;;
    legal)
      section_title="Legal"
      section_intro="This section documents policies and terms that govern use of our services, websites, and downloadable materials."
      default_blocks=$(cat <<'HTML'
<h3>Policy Practices</h3>
<ul>
  <li>Regular policy review with legal and security partners.</li>
  <li>Accessible language for customer and stakeholder clarity.</li>
  <li>Versioned updates with transparent effective dates.</li>
</ul>
HTML
)
      ;;
    contact)
      section_title="Contact"
      section_intro="Connect with the right team quickly for sales planning, active project support, or partner collaboration."
      default_blocks=$(cat <<'HTML'
<h3>Response Standards</h3>
<ul>
  <li>Sales inquiries: same business day initial response.</li>
  <li>Support requests: priority triage within one hour.</li>
  <li>Partnership requests: reviewed weekly by alliances team.</li>
</ul>
HTML
)
      ;;
    locations)
      section_title="Locations"
      section_intro="Our delivery teams operate in hybrid hubs to support clients across North America with flexible collaboration models."
      default_blocks=$(cat <<'HTML'
<h3>Office Operations</h3>
<ul>
  <li>Client workshops available onsite and virtual.</li>
  <li>Executive briefings by appointment.</li>
  <li>Regional teams aligned to local time zones.</li>
</ul>
HTML
)
      ;;
    *)
      section_title="Overview"
      section_intro="This page provides project-relevant context used in migration testing and template validation."
      default_blocks=""
      ;;
  esac

  spotlight=""
  case "$slug" in
    about-leadership)
      spotlight=$(cat <<'HTML'
<h3>Leadership Team</h3>
<ul>
  <li><strong>Maya Grant</strong> - Chief Executive Officer</li>
  <li><strong>Owen Castillo</strong> - Chief Delivery Officer</li>
  <li><strong>Priya Raman</strong> - VP, Content Strategy</li>
  <li><strong>Ethan Brooks</strong> - VP, Engineering</li>
</ul>
HTML
)
      ;;
    about-careers)
      spotlight=$(cat <<'HTML'
<h3>Open Roles Snapshot</h3>
<ul>
  <li>Senior Drupal Engineer (Remote, US)</li>
  <li>Content Migration Analyst (Hybrid, Phoenix)</li>
  <li>UX Content Designer (Remote, US)</li>
</ul>
HTML
)
      ;;
    service-strategy-workshop)
      spotlight=$(cat <<'HTML'
<h3>Workshop Deliverables</h3>
<ol>
  <li>Migration readiness assessment.</li>
  <li>Destination template map and ownership model.</li>
  <li>90-day execution plan with risk register.</li>
</ol>
HTML
)
      ;;
    service-drupal-build)
      spotlight=$(cat <<'HTML'
<h3>Build Scope Includes</h3>
<ul>
  <li>Component-driven theme implementation.</li>
  <li>Structured content model with editorial guidance.</li>
  <li>Deployment pipeline and environment strategy.</li>
</ul>
HTML
)
      ;;
    resource-checklist-content-inventory)
      spotlight=$(cat <<'HTML'
<h3>Checklist Preview</h3>
<p>Track page owner, business value, destination template, and redirect intent for each legacy URL.</p>
HTML
)
      ;;
    resource-guide-redirect-strategy)
      spotlight=$(cat <<'HTML'
<h3>Guide Outline</h3>
<ol>
  <li>Classify legacy URL intents.</li>
  <li>Map canonical destinations by template.</li>
  <li>Validate redirect behavior before launch.</li>
</ol>
HTML
)
      ;;
    contact-sales)
      spotlight=$(cat <<'HTML'
<h3>Sales Contacts</h3>
<ul>
  <li><strong>Jordan Alvarez</strong> - Director of Solutions<br>Email: jordan.alvarez@example.com<br>Phone: (602) 555-0141</li>
  <li><strong>Claire Nguyen</strong> - Enterprise Account Executive<br>Email: claire.nguyen@example.com<br>Phone: (602) 555-0188</li>
  <li><strong>Marcus Bell</strong> - Partnerships Lead<br>Email: marcus.bell@example.com<br>Phone: (602) 555-0133</li>
</ul>
HTML
)
      ;;
    contact-support)
      spotlight=$(cat <<'HTML'
<h3>Support Team Directory</h3>
<ul>
  <li><strong>Ana Flores</strong> - Support Manager<br>Email: ana.flores@example.com<br>Phone: (602) 555-0162</li>
  <li><strong>Devon Price</strong> - Technical Support Engineer<br>Email: devon.price@example.com<br>Phone: (602) 555-0194</li>
  <li><strong>Leah Patel</strong> - Customer Success Specialist<br>Email: leah.patel@example.com<br>Phone: (602) 555-0119</li>
</ul>
HTML
)
      ;;
    locations-phoenix)
      spotlight=$(cat <<'HTML'
<h3>Phoenix Hub</h3>
<p>4100 East Camelback Road, Suite 220, Phoenix, AZ 85018</p>
<p>Hours: Monday-Friday, 8:00 AM-5:30 PM MST</p>
HTML
)
      ;;
    locations-denver)
      spotlight=$(cat <<'HTML'
<h3>Denver Hub</h3>
<p>1600 Market Street, Suite 900, Denver, CO 80202</p>
<p>Hours: Monday-Friday, 8:00 AM-5:30 PM MT</p>
HTML
)
      ;;
  esac

  cat <<HTML
<article class="legacy-page">
  <h2>${title}</h2>
  <p><strong>Section:</strong> ${section_title}</p>
  <p>${section_intro}</p>
  <p>This page is part of a deterministic migration fixture set (record ${index}) and is used to validate content quality, metadata continuity, and destination template mapping.</p>
  ${default_blocks}
  ${spotlight}
  <p>Related pages: <a href="${LEGACY_BASE_URL}/about/company-overview/">Company Overview</a>, <a href="${LEGACY_BASE_URL}/services/strategy-workshop/">Strategy Workshop</a>, and <a href="${LEGACY_BASE_URL}/resources/guide-redirect-strategy/">Redirect Strategy Guide</a>.</p>
  <p><img src="${LEGACY_BASE_URL}/wp-content/uploads/2025/01/${slug}.jpg" alt="${title}" /></p>
</article>
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
updated=0
definition_count=0

while IFS='|' read -r slug title section parent_slug has_description; do
  [ -z "$slug" ] && continue
  definition_count=$((definition_count + 1))

  existing_id="$(get_page_id_by_slug "$slug")"

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

  if [ -n "$existing_id" ]; then
    page_id="$existing_id"
    wp_cli post update "$page_id" \
      --post_title="$title" \
      --post_parent="$parent_id" \
      --post_content="$html_content" >/dev/null
    updated=$((updated + 1))
    echo "Updated: $slug (ID: $page_id)"
  else
    page_id="$(wp_cli post create \
      --post_type=page \
      --post_status=publish \
      --post_title="$title" \
      --post_name="$slug" \
      --post_parent="$parent_id" \
      --post_content="$html_content" \
      --porcelain)"
    created=$((created + 1))
    echo "Created: $slug (ID: $page_id)"
  fi

  page_ids["$slug"]="$page_id"

  legacy_url="${LEGACY_BASE_URL}/${section}/${slug}/"
  if [ -n "$parent_slug" ]; then
    legacy_url="${LEGACY_BASE_URL}/${parent_slug}/${slug}/"
  fi

  wp_cli post meta update "$page_id" legacy_url "$legacy_url" >/dev/null
  wp_cli post meta update "$page_id" seo_title "${title} | Legacy Prototype" >/dev/null

  if [ "$has_description" = "1" ]; then
    wp_cli post meta update "$page_id" seo_description "Prototype SEO description for ${title}." >/dev/null
  else
    wp_cli post meta delete "$page_id" seo_description >/dev/null 2>&1 || true
  fi

done <<<"$PAGE_DEFINITIONS"

total_with_legacy="$(wp_cli post list --post_type=page --meta_key=legacy_url --post_status=publish --format=count)"

echo
echo "Fixture generation complete."
echo "Definitions processed: $definition_count"
echo "Created this run: $created"
echo "Updated this run: $updated"
echo "Published pages with legacy_url meta: $total_with_legacy"
