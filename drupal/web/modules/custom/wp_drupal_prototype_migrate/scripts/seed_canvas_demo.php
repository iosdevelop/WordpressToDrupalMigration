<?php

/**
 * @file
 * Seeds the Canvas demo page with a production-export-inspired layer tree.
 *
 * Run with:
 *   drush php:script web/modules/custom/wp_drupal_prototype_migrate/scripts/seed_canvas_demo.php
 */

declare(strict_types=1);

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\CanvasPage;

$component_ids = [
  'hero',
  'layout-container',
  'card',
  'checkerboard-container',
  'checkerboard',
  'text-content',
  'accordion-container',
  'accordion',
  'testimonial',
];

$components = [];
foreach ($component_ids as $short_id) {
  $id = "sdc.prototype_showcase.$short_id";
  $component = Component::load($id);
  if (!$component) {
    throw new RuntimeException("Canvas component $id is unavailable. Rebuild caches first.");
  }
  $components[$short_id] = $component;
}

$item = static function (
  string $uuid,
  string $component,
  array $inputs,
  string $label,
  ?string $parent_uuid = NULL,
  ?string $slot = NULL,
) use ($components): array {
  $value = [
    'uuid' => $uuid,
    'component_id' => $components[$component]->id(),
    'component_version' => $components[$component]->getActiveVersion(),
    'inputs' => $inputs,
    'label' => $label,
  ];
  if ($parent_uuid !== NULL) {
    $value['parent_uuid'] = $parent_uuid;
    $value['slot'] = $slot;
  }
  return $value;
};

$hero = '10000000-0000-4000-8000-000000000001';
$cards = '20000000-0000-4000-8000-000000000001';
$checkerboards = '30000000-0000-4000-8000-000000000001';
$columns = '40000000-0000-4000-8000-000000000001';
$accordions = '50000000-0000-4000-8000-000000000001';

$tree = [
  $item($hero, 'hero', [
    'eyebrow' => 'Syracuse University · IVMF',
    'heading' => 'Empowering the military-connected community',
    'body' => 'Programs, resources, and research for veterans and military families.',
    'link_text' => 'Explore the programs',
    'link_url' => '/canvas-layout-demo',
  ], 'Standard Hero H1 Only'),
  $item($cards, 'layout-container', [
    'heading' => 'Find your next step',
    'columns' => 3,
  ], 'Three Card Container'),
  $item('20000000-0000-4000-8000-000000000002', 'card', [
    'number' => '01', 'heading' => 'Career training',
    'content' => 'Build practical skills and translate military experience into a meaningful civilian career.',
    'link_text' => 'Explore training', 'link_url' => '/canvas-layout-demo',
  ], 'Career Training Card', $cards, 'content'),
  $item('20000000-0000-4000-8000-000000000003', 'card', [
    'number' => '02', 'heading' => 'Entrepreneurship',
    'content' => 'Turn an idea into a resilient business with expert instruction and a nationwide network.',
    'link_text' => 'Build a business', 'link_url' => '/canvas-layout-demo',
  ], 'Entrepreneurship Card', $cards, 'content'),
  $item('20000000-0000-4000-8000-000000000004', 'card', [
    'number' => '03', 'heading' => 'Community support',
    'content' => 'Connect to resources designed for service members, veterans, and military families.',
    'link_text' => 'Find resources', 'link_url' => '/canvas-layout-demo',
  ], 'Community Support Card', $cards, 'content'),
  $item($checkerboards, 'checkerboard-container', [], 'Two Card Checkerboard'),
  $item('30000000-0000-4000-8000-000000000002', 'checkerboard', [
    'eyebrow' => 'Two Card Checkerboard', 'heading' => 'Opportunity after service',
    'content' => 'Alternating media and text modules create a strong editorial rhythm.',
  ], 'Opportunity Checkerboard', $checkerboards, 'checkerboard_items'),
  $item('30000000-0000-4000-8000-000000000003', 'checkerboard', [
    'eyebrow' => 'Reusable component', 'heading' => 'Built around real journeys',
    'content' => 'Canvas inputs map cleanly to headings, text, media, and calls to action.',
  ], 'Journeys Checkerboard', $checkerboards, 'checkerboard_items'),
  $item($columns, 'layout-container', [
    'heading' => 'One flexible system. Many stories.', 'columns' => 2,
  ], 'Two Column Text'),
  $item('40000000-0000-4000-8000-000000000002', 'text-content', [
    'heading' => 'Migration-ready',
    'content' => 'The component system gives migrated content a consistent presentation target.',
  ], 'Migration Text', $columns, 'content'),
  $item('40000000-0000-4000-8000-000000000003', 'text-content', [
    'heading' => 'Canvas-native',
    'content' => 'Editors can rearrange these layers and change every component input visually.',
  ], 'Canvas Text', $columns, 'content'),
  $item($accordions, 'accordion-container', [
    'heading' => 'How the reconstruction works',
  ], 'Accordion Container 3'),
  $item('50000000-0000-4000-8000-000000000002', 'accordion', [
    'title' => 'What came from the live configuration?',
    'content' => 'The component hierarchy, slot relationships, pattern names, and composition rules.',
  ], 'Live Configuration', $accordions, 'accordion_items'),
  $item('50000000-0000-4000-8000-000000000003', 'accordion', [
    'title' => 'Why were components recreated?',
    'content' => 'The export referenced the private Syracuse theme, so compatible local SDCs replace it.',
  ], 'Recreated Components', $accordions, 'accordion_items'),
  $item('50000000-0000-4000-8000-000000000004', 'accordion', [
    'title' => 'Can editors change the layout?',
    'content' => 'Yes. Every seeded item is a native Canvas layer with editable inputs and drag-and-drop placement.',
  ], 'Editor Capabilities', $accordions, 'accordion_items'),
  $item('60000000-0000-4000-8000-000000000001', 'testimonial', [
    'quote' => '<p>IVMF gave me the tools, confidence, and community to take the next step after service.</p>',
    'cite_name' => 'Jill Leary',
    'program' => 'Entrepreneurship Bootcamp for Veterans',
    'program_year' => "'22",
    'school' => 'Syracuse University',
    'workplace_name' => 'Veteran-Owned Business',
    'theme' => 'dark',
    'link_url' => '/canvas-layout-demo',
    'link_text' => 'Read the full story',
    'accessible_link_text' => 'Read Jill Leary’s full story',
  ], 'Testimonial'),
];

$storage = \Drupal::entityTypeManager()->getStorage('canvas_page');
$ids = $storage->getQuery()
  ->accessCheck(FALSE)
  ->condition('title', 'Canvas Layout Demo')
  ->execute();

/** @var \Drupal\canvas\Entity\CanvasPage $page */
$page = $ids ? $storage->load(reset($ids)) : CanvasPage::create([
  'title' => 'Canvas Layout Demo',
  'owner' => 1,
  'status' => 1,
  'path' => ['alias' => '/canvas-layout-demo'],
]);
$page->set('description', 'Native Canvas reconstruction of the supplied IVMF configuration export.');
$page->set('components', $tree);

$violations = $page->validate();
if ($violations->count()) {
  foreach ($violations as $violation) {
    fwrite(STDERR, $violation->getPropertyPath() . ': ' . $violation->getMessage() . PHP_EOL);
  }
  throw new RuntimeException('Canvas demo page validation failed.');
}

$page->save();
print "Seeded Canvas page {$page->id()} with " . count($tree) . " native layers." . PHP_EOL;
