# Demo Walkthrough (5-10 Minutes)

## Goal for Prototype Demo

Show pragmatic migration architecture judgment, not feature volume.

## Suggested Flow

1. **Open repository structure (1 minute)**
   - show `scripts/`, `data/`, and Drupal custom module
   - explain the prototype focus: representative subset with scale-ready structure

2. **Generate/refresh mock content and data (2 minutes)**
   - run:
     - `./scripts/create-mock-pages.sh`
     - `./scripts/export-wordpress-pages.sh`
     - `./scripts/generate-old-to-new-map.sh`
   - highlight deterministic fixtures and edge-case coverage

3. **Show migration inputs (1 minute)**
   - open `data/wordpress-pages.csv`
   - open `data/old-to-new-map.csv`
   - open `data/sample-redirects.csv`
   - explain consolidation model (many legacy URLs -> fewer Drupal targets)

4. **Run Drupal migrations (2 minutes)**
   - from `/drupal` run:
     - `ddev drush migrate:import wp_pages -y`
     - `ddev drush migrate:import wp_redirects -y`
   - optionally show `ddev drush migrate:status`

5. **Verify outcomes (1 minute)**
   - show migrated `wp_migrated_page` nodes in Drupal admin
   - show redirect entities were created
   - point to `field_legacy_url`, `field_seo_title`, and `field_seo_description`

6. **Close with scaling narrative (1-2 minutes)**
   - explain how the same pattern scales to 275 -> 125 through explicit mapping matrix and template-aware destination modeling

## Key Talking Points

- Scope discipline: prioritized credible migration mechanics over production completeness.
- Separation of concerns: fixture generation, export/mapping, and Drupal import are decoupled.
- Repeatability: deterministic scripts and clear DDEV commands.
- Drupal best practice orientation: custom module boundaries, Migrate API usage, and process plugins.
- Continuity mindset: redirects and SEO metadata are included early.

## Tradeoffs to Mention Proactively

- media migration intentionally deferred
- SEO parity intentionally partial
- no advanced editorial workflow mapping yet
- redirect mapping is prototype-level and should become governed data in production

## If You Have Extra Time

1. Show rollback commands (`migrate:rollback`) to prove reset/repeat safety.
2. Show where to plug in production mapping logic (`old-to-new-map.csv` generation step).
3. Mention CI extensions for automated migration QA checks.
