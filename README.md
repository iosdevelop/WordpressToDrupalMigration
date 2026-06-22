# WordPress to Drupal Migration Prototype

Prototype that demonstrates a practical WordPress-to-Drupal migration workflow using:

- deterministic WordPress fixtures (WP-CLI)
- export and mapping scripts
- Drupal 11 custom migration module
- sample redirect migration concept
- DDEV-based local environments for both CMSs

## Project Purpose

This repository is a proof of concept for a future migration where roughly **275 legacy WordPress pages** are consolidated into about **125 Drupal pages** built on predefined Drupal templates.

The prototype intentionally migrates only a representative subset while keeping the structure scalable.

## Architecture Summary

1. WordPress fixture script creates realistic sample pages with metadata and edge cases.
2. Export script produces CSV source data for Drupal migrations.
3. Mapping script generates old-to-new URL map and sample redirect CSV.
4. Drupal custom module (`wp_drupal_prototype_migrate`) imports pages and redirects using Migrate API.

See:

- [docs/architecture.md](docs/architecture.md)
- [docs/demo-walkthrough.md](docs/demo-walkthrough.md)

## Repository Layout

```text
/README.md
/docs/architecture.md
/docs/demo-walkthrough.md
/scripts/create-mock-pages.sh
/scripts/export-wordpress-pages.sh
/scripts/generate-old-to-new-map.sh
/data/old-to-new-map.csv
/data/sample-redirects.csv
/wordpress/.ddev/config.yaml
/drupal/.ddev/config.yaml
/drupal/composer.json
/drupal/web/modules/custom/wp_drupal_prototype_migrate/
```

## Prerequisites

- Docker Desktop or compatible Docker runtime
- DDEV
- Bash
- Git

Optional but useful:

- `jq` for inspecting JSON outputs during local debugging

## Local Setup

### 1) Bootstrap WordPress (`/wordpress`)

```bash
cd wordpress
ddev start

# First run only.
ddev wp core download
ddev wp config create --dbname=db --dbuser=db --dbpass=db --dbhost=db

ddev wp core install \
  --url='https://wp-prototype.ddev.site' \
  --title='WP Prototype' \
  --admin_user=admin \
  --admin_password=admin \
  --admin_email=admin@example.com

cd ..
```

### 2) Generate mock WordPress content and export migration source data

```bash
./scripts/create-mock-pages.sh
./scripts/export-wordpress-pages.sh
./scripts/generate-old-to-new-map.sh
```

Outputs:

- `data/wordpress-pages.csv`
- `data/old-to-new-map.csv`
- `data/sample-redirects.csv`

The mapping script also copies data into Drupal module-local import files:

- `drupal/web/modules/custom/wp_drupal_prototype_migrate/data/wp-pages.csv`
- `drupal/web/modules/custom/wp_drupal_prototype_migrate/data/sample-redirects.csv`

### 3) Bootstrap Drupal 11 (`/drupal`)

```bash
cd drupal
ddev start
ddev composer install

# First run only.
ddev drush site:install standard \
  --account-name=admin \
  --account-pass=admin \
  --site-name='Drupal Prototype' \
  --yes

# Enable migration dependencies and custom prototype module.
ddev drush en migrate_plus migrate_tools migrate_source_csv redirect wp_drupal_prototype_migrate -y
ddev drush theme:enable prototype_showcase -y
ddev drush config:set system.theme default prototype_showcase -y
ddev drush cr
```

The custom theme front page includes a local reconstruction of the reusable
layout patterns found in the supplied IVMF Canvas configuration export. See
[the Canvas configuration analysis](docs/live-canvas-config-analysis.md) for
the recovered component model and the limits of a config-only reconstruction.

To safely synchronize the portable content structure from the June 22 sandbox
export without importing production services or permissions:

```bash
./scripts/import-sandbox-structure.sh
```

The script uses an explicit allowlist for the FAQ, Landing Page, People, and
Program Page types and their fields/displays. It never imports authentication,
roles, S3, mail, Redis, environment-specific modules, or private-theme config.

### 4) Run sample migrations

```bash
cd drupal

ddev drush migrate:status
ddev drush migrate:import wp_pages -y
ddev drush migrate:import wp_redirects -y
```

### 5) Roll back migrations (demo reset)

```bash
cd drupal
ddev drush migrate:rollback wp_redirects -y
ddev drush migrate:rollback wp_pages -y
```

## WordPress Fixture Details

`scripts/create-mock-pages.sh` creates 40+ deterministic pages and includes edge cases:

- nested page (`company` > `company-team-directory`)
- long title page
- missing `seo_description` on one page
- internal links pointing to legacy URL patterns
- basic image markup in body HTML
- post meta fields:
  - `legacy_url`
  - `seo_title`
  - `seo_description`

The script skips pages when a slug already exists.

## Migration Prototype Details

Drupal module: `wp_drupal_prototype_migrate`

Included migrations:

- `migrations/wp_pages.yml`
  - imports page title/body
  - maps legacy/SEO metadata into prototype fields
  - sets deterministic alias format `/prototype/<slug>`
- `migrations/wp_redirects.yml`
  - imports redirect intent from CSV
  - demonstrates legacy URL to destination mapping with 301 status

Install hook scaffolds destination content type and fields:

- content type: `wp_migrated_page`
- fields:
  - `field_legacy_url`
  - `field_seo_title`
  - `field_seo_description`

## Redirect Mapping Demonstration

`data/old-to-new-map.csv` and `data/sample-redirects.csv` illustrate consolidation logic where many old URLs can map to fewer canonical Drupal destinations by section (`/about`, `/services`, `/industries`, etc.).

This provides a clear prototype narrative for handling a 275 -> 125 migration model.

## Prototype Limitations

This is intentionally scoped and not production-ready:

- no full media file migration
- no complete SEO parity implementation
- no editorial workflow or revision migration
- no multilingual handling
- no automated QA dashboards yet

## IA Workbook Content Extraction

The current information architecture workbook is stored at
`data/IVMF-Website-2026-Info-Architecture-v01.xlsx`. Extract migration-ready
content from its WordPress URL inventory with:

```bash
/home/mozart/.venvs/ivmf-workbook/bin/python \
  scripts/extract_ivmf_content.py
```

The ignored local `data/crawl-output/` directory receives page summaries,
full main-content HTML/text, image references, testimonial instances, a
deduplicated testimonial repository, and People directory/profile data. Target
Drupal page matches in the inventory are suggestions and require editorial
review; the source workbook currently contains only six manually confirmed
redirect selections.

## Scaling Path: 275 WordPress Pages -> 125 Drupal Pages

The prototype is structured so scaling is mechanical, not architectural rework:

1. Extend fixture/export data shape to include all mapping metadata required for template assignment.
2. Replace section-based heuristic with explicit mapping matrix (`old_url -> destination template + canonical path`).
3. Add additional migrations for media, taxonomies, and authored relationships.
4. Add QA checks for content parity, redirect correctness, and metadata completeness.
5. Add production hardening around performance, governance, and rollback plans.

## Quick Demo Script

Use [docs/demo-walkthrough.md](docs/demo-walkthrough.md) for a 5-10 minute demo walkthrough.
