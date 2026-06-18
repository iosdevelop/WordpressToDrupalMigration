# Project Guidelines

## Architecture
- WordPress fixtures live under /wordpress; Drupal prototype lives under /drupal.
- Migration flow is CSV-driven: scripts export to /data and copy into the Drupal custom module.
- Custom Drupal migration module: /drupal/web/modules/custom/wp_drupal_prototype_migrate.
- See docs/architecture.md and docs/demo-walkthrough.md for full context.

## Build and Test
- Start WordPress: `cd wordpress && ddev start` (first run: WP core download + config + install).
- Generate data: `./scripts/create-mock-pages.sh`, `./scripts/export-wordpress-pages.sh`, `./scripts/generate-old-to-new-map.sh`.
- Start Drupal: `cd drupal && ddev start && ddev composer install`.
- Run migrations: `ddev drush migrate:import wp_pages -y` and `ddev drush migrate:import wp_redirects -y`.
- Rollback for reset: `ddev drush migrate:rollback wp_pages -y` and `ddev drush migrate:rollback wp_redirects -y`.

## Conventions
- DDEV is required for both WordPress and Drupal; scripts assume running containers.
- CSVs are the contract between systems; keep /data outputs and module-local copies in sync.
- Redirects are modeled via CSV + migration; do not hardcode redirects in code.
- For new migration logic, prefer adding or extending scripts and migration YAML in the custom module.
