# Live IVMF Canvas configuration analysis

Sources:

- `data/config-ivmf-sites-syr-edu-2026-06-11-14-57.tar.gz` (exported June 11, 2026)
- `data/config-ivmf-sites-syr-edu-2026-06-22-15-54.tar.gz` (exported June 22, 2026)

The June 22 export is the current architecture reference. Compared with June
11, it adds three reusable basic-block component records (Blue Bar Header,
Secondary Navigation, and Universal Footer), separates reusable content blocks
from inline blocks in Canvas folders, and enables two additional
environment-specific modules. The referenced reusable block content is not
included in a configuration export.

## What the export proves

The live site uses Drupal Canvas with the custom `syracuse_default` front-end theme and `gin` admin theme. The export contains:

- 82 Canvas component definitions
- 17 Canvas component folders
- 6 reusable Canvas patterns
- 2 JavaScript components
- a global asset library and brand kit

The six patterns are:

1. Standard Hero H1 Only
2. Three Card Container
3. Two Card Checkerboard
4. Two Column Text
5. Accordion Container 3
6. Slideshow with 3 Slides

Canvas stores each pattern as a flat `component_tree`. Parent components expose named slots, and children refer to a `parent_uuid`, slot name, and position. Examples include cards inside a layout container's `content` slot and accordion items inside an `accordion_items` slot.

The exported portable content model also defines FAQ, Landing Page, People,
and Program Page node types. The local site imports only those types, their
fields, and their form/view displays through
`scripts/import-sandbox-structure.sh`. The allowlist deliberately excludes
roles, authentication, infrastructure, storage, mail, caching, and production
theme configuration.

## Important limitation

The export is configuration, not a complete site build. All useful visual components depend on the custom `syracuse_default` theme, whose Single Directory Component Twig, CSS, JavaScript, and assets are absent. The local project is Drupal 10.6, while the archive also expects Drupal Canvas and a large contributed-module stack. A full config import is therefore neither reproducible nor safe.

## Local reconstruction

The `prototype_showcase` front page now implements a dependency-free pattern gallery based on the exported composition model. It recreates the hero, three-card container, two-card checkerboard, two-column text, and three-item accordion with semantic Twig and responsive CSS. Existing Drupal content still renders in the standard content region below the gallery.

This is a visual/layout analogue, not a claim to reproduce Syracuse's proprietary theme. Native Canvas editing should be a separate upgrade track requiring:

1. the exact live Drupal and Canvas package versions;
2. the `syracuse_default` theme source and assets;
3. the full module dependency set;
4. a clean Drupal 11-compatible install; and
5. selective config import after UUID and environment-specific settings are sanitized.

The local `prototype_showcase` theme includes an IVMF Testimonial analogue
whose inputs match the live Canvas component: quote, citation, program,
program year, school, workplace, theme, image, link, and accessible link text.
