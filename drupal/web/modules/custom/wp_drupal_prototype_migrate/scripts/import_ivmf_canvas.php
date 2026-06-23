<?php

declare(strict_types=1);

use Drupal\canvas\Entity\Page;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\ComponentSource\ComponentSourceManager;

function import_first_existing_dir(array $candidates): string {
  foreach ($candidates as $candidate) {
    if (is_dir($candidate)) {
      return $candidate;
    }
  }
  return $candidates[0];
}

$output_dir = import_first_existing_dir([
  dirname(__DIR__) . '/data/crawl-output',
  dirname(__DIR__, 6) . '/data/crawl-output',
  '/var/www/data/crawl-output',
]);
$pages_file = $output_dir . '/ivmf-content-pages.jsonl';
$people_file = $output_dir . '/ivmf-people.csv';
$testimonials_file = $output_dir . '/ivmf-testimonials-deduplicated.csv';

if (!is_file($pages_file)) {
  throw new RuntimeException("Missing crawl output: {$pages_file}");
}

$component_source_manager = \Drupal::service(ComponentSourceManager::class);
\assert($component_source_manager instanceof ComponentSourceManager);
$component_source_manager->generateComponents();
\Drupal::entityTypeManager()->getStorage('component')->resetCache();

function import_clean_text(string $value): string {
  return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
}

function import_slug(string $value): string {
  $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
  $value = strtolower($value);
  $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?: '';
  return trim($value, '-');
}

function import_alias_from_url(string $url): string {
  $path = parse_url($url, PHP_URL_PATH) ?: '/';
  $segments = array_values(array_filter(array_map('trim', explode('/', trim($path, '/')))));
  if (!$segments) {
    return '/canvas-import/home';
  }
  $segments = array_map(static fn(string $segment): string => import_slug($segment), $segments);
  return '/canvas-import/' . implode('/', $segments);
}

function import_excerpt(string $text, int $length = 260): string {
  $text = import_clean_text($text);
  if (mb_strlen($text) <= $length) {
    return $text;
  }
  return mb_substr($text, 0, $length - 1) . '…';
}

function import_text_sections(string $html): array {
  if ($html === '') {
    return [];
  }
  $dom = new DOMDocument();
  libxml_use_internal_errors(true);
  $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
  libxml_clear_errors();
  $xpath = new DOMXPath($dom);
  $sections = [];
  $current_heading = NULL;
  $current_chunks = [];
  foreach ($xpath->query('//*') as $node) {
    if (!in_array($node->nodeName, ['h2', 'h3', 'h4', 'p', 'li'], true)) {
      continue;
    }
    $text = import_clean_text($node->textContent ?? '');
    if ($text === '') {
      continue;
    }
    if (in_array($node->nodeName, ['h2', 'h3'], true)) {
      if ($current_heading !== NULL && $current_chunks) {
        $sections[] = ['heading' => $current_heading, 'content' => implode("\n\n", $current_chunks)];
      }
      $current_heading = $text;
      $current_chunks = [];
      continue;
    }
    $current_chunks[] = $text;
    if (count($current_chunks) >= 3) {
      $sections[] = ['heading' => $current_heading ?: 'Content', 'content' => implode("\n\n", $current_chunks)];
      $current_heading = NULL;
      $current_chunks = [];
    }
  }
  if ($current_heading !== NULL && $current_chunks) {
    $sections[] = ['heading' => $current_heading, 'content' => implode("\n\n", $current_chunks)];
  }
  return array_values(array_filter($sections, static fn(array $section): bool => trim((string) ($section['heading'] ?? '')) !== '' || trim((string) ($section['content'] ?? '')) !== ''));
}

function import_first_numbers(string $text, int $limit = 4): array {
  preg_match_all('/\b\d{1,3}(?:,\d{3})*(?:\.\d+)?\b/u', $text, $matches);
  $numbers = [];
  foreach ($matches[0] as $match) {
    if (!in_array($match, $numbers, true)) {
      $numbers[] = $match;
    }
    if (count($numbers) >= $limit) {
      break;
    }
  }
  return $numbers;
}

function import_image_payload(?array $image): ?array {
  if (!$image || empty($image['url'])) {
    return NULL;
  }
  return [
    'src' => $image['url'],
    'alt' => $image['alt'] ?? '',
  ];
}

function import_first_image_payload(array $page): ?array {
  $image = $page['images'][0] ?? NULL;
  if (!$image) {
    return NULL;
  }
  return import_image_payload([
    'url' => $image['url'] ?? '',
    'alt' => $image['alt'] ?? ($image['title'] ?? ''),
  ]);
}

function import_filter_nulls(array $value): array {
  foreach ($value as $key => $item) {
    if (is_array($item)) {
      $item = import_filter_nulls($item);
      if ($item === []) {
        unset($value[$key]);
        continue;
      }
      $value[$key] = $item;
      continue;
    }
    if ($item === NULL) {
      unset($value[$key]);
    }
  }
  return $value;
}

function import_load_jsonl(string $path): array {
  $pages = [];
  foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $page = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    $pages[$page['source_url']] = $page;
  }
  return $pages;
}

function import_load_people(string $path): array {
  if (!is_file($path)) {
    return [];
  }
  $rows = [];
  $handle = fopen($path, 'rb');
  if (!$handle) {
    return [];
  }
  $header = fgetcsv($handle);
  if (!$header) {
    fclose($handle);
    return [];
  }
  $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]) ?: $header[0];
  while (($row = fgetcsv($handle)) !== false) {
    $data = array_combine($header, $row);
    if (!$data) {
      continue;
    }
    $data['categories'] = json_decode($data['categories'] ?? '[]', true) ?: [];
    $data['profile_links'] = json_decode($data['profile_links'] ?? '[]', true) ?: [];
    $rows[] = $data;
  }
  fclose($handle);
  return $rows;
}

function import_load_testimonials(string $path): array {
  if (!is_file($path)) {
    return [];
  }
  $rows = [];
  $handle = fopen($path, 'rb');
  if (!$handle) {
    return [];
  }
  $header = fgetcsv($handle);
  if (!$header) {
    fclose($handle);
    return [];
  }
  $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]) ?: $header[0];
  while (($row = fgetcsv($handle)) !== false) {
    $data = array_combine($header, $row);
    if (!$data) {
      continue;
    }
    $rows[] = $data;
  }
  fclose($handle);
  return $rows;
}

function import_component_items(array $components, string $short_id, array $inputs, string $label, string $uuid, ?string $parent_uuid = NULL, ?string $slot = NULL): array {
  if (!isset($components[$short_id])) {
    $components[$short_id] = import_load_component($short_id);
  }
  if (!$components[$short_id]) {
    throw new RuntimeException("Canvas component unavailable: {$short_id}");
  }
  $item = [
    'uuid' => $uuid,
    'component_id' => $components[$short_id]->id(),
    'component_version' => $components[$short_id]->getActiveVersion(),
    'inputs' => import_filter_nulls($inputs),
    'label' => $label,
  ];
  if ($parent_uuid !== NULL) {
    $item['parent_uuid'] = $parent_uuid;
    $item['slot'] = $slot;
  }
  return $item;
}

function import_load_component(string $short_id): ?Component {
  $component_id = "sdc.prototype_showcase.$short_id";
  $component = Component::load($component_id);
  if ($component) {
    return $component;
  }
  $storage = \Drupal::entityTypeManager()->getStorage('component');
  $matches = $storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('id', $component_id)
    ->execute();
  return $matches ? $storage->load(reset($matches)) : NULL;
}

function import_load_components(array $ids): array {
  $components = [];
  foreach ($ids as $short_id) {
    $components[$short_id] = import_load_component($short_id);
  }
  return $components;
}

function import_upsert_canvas_page(string $title, string $alias, string $description, array $components_tree): Page {
  $storage = \Drupal::entityTypeManager()->getStorage('canvas_page');
  $ids = $storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('title', $title)
    ->execute();

  /** @var \Drupal\canvas\Entity\Page $page */
  $page = $ids ? $storage->load(reset($ids)) : Page::create([
    'title' => $title,
    'owner' => 1,
    'status' => 1,
    'path' => ['alias' => $alias],
  ]);
  $page->set('description', $description);
  $page->set('components', $components_tree);
  $violations = $page->validate();
  if ($violations->count()) {
    $messages = [];
    foreach ($violations as $violation) {
      $messages[] = $violation->getPropertyPath() . ': ' . $violation->getMessage();
    }
    throw new RuntimeException("Canvas validation failed for {$title}: " . implode('; ', $messages));
  }
  $page->save();
  return $page;
}

$components = import_load_components([
  'hero',
  'layout-container',
  'card',
  'checkerboard-container',
  'checkerboard',
  'text-content',
  'list-item',
  'stats',
  'fact-stats',
  'accordion-container',
  'accordion',
  'testimonial',
]);

$content_pages = import_load_jsonl($pages_file);
$people = import_load_people($people_file);
$testimonials = import_load_testimonials($testimonials_file);

$page_refs = [];
foreach ($content_pages as $source_url => $page) {
  $page_refs[$page['page_title'] ?? $source_url] = $page;
}

$selected_pages = [];
foreach ([
  'About IVMF',
  'Team',
  'Impact',
  'Why Syracuse University',
  'Partners & Funders',
  'BL History',
  'History',
  'Bunker Labs: A Legacy of Impact',
  'Programs',
  'Our Programs',
  'Entrepreneurship',
  'Getting Started',
  'Ideation',
  'Boots to Business',
  'Startup Training Resources to Inspire Veteran Entrepreneurship (STRIVE)',
  'Start Up',
  'Veteran Women Igniting the Spirit of Entrepreneurship (V-WISE)',
  'Growth',
  'Veteran EDGE',
  'Vet100',
  'Entrepreneurship Bootcamp for Veterans (EBV)',
  'Entrepreneurship Bootcamp for Veterans’ Families',
  'EBV-F Application',
  'Entrepreneurship Bootcamp for Veterans Accelerate',
  'EBV Accelerate Application',
  'Career Training',
  'Onward to Opportunity Application',
  'Career Preparation',
  'O2O Locations',
  'The Onward to Opportunity Program for the Florida Military Communities',
  'Southern California',
  'Joint Base San Antonio',
  'Fort Carson',
  'Camp Lejeune',
  'National Capital Region',
  'Joint Base Lewis-McChord',
  'Hampton Roads',
  'Fort Campbell',
  'Fort Drum',
  'Fort Hood',
  'Charleston, SC',
  'Navy Region Northwest',
  'Fort Knox',
  'FAQ',
  'Employer Partners',
  'Ambassador Program',
  'Bunker Labs Ambassador - Thank You',
  'Ambassador Communities',
  'CEOcircle',
  'Military Founders Lab',
  'Thank You!',
  'Coalition for Veteran Owned Business (CVOB)',
  'CVOB Military Entrepreneurship Forum (MEF)',
  'CVOB Mission to Marketplace (M2M)',
  'Center of Excellence for Veteran Entrepreneurship (COE)',
  'Research + Analytics + Policy',
  'Research & Analytics Team',
  'Applied Research',
  'Apply for the Bernard D. and Louise C. Rostker IVMF Dissertation Research Fund',
  'Articles Archive',
  'National Survey of Military-Affiliated Entrepreneurs',
  'National Survey of Military-Affiliated Entrepreneurs Toolkit - 2024',
  'Research Projects',
  'Research Reviews',
  'Current Projects',
  'Past Projects',
  'Policy Engagement',
  'IVMF Policy Priorities',
  'Evaluation & Analytics',
  'The Employment Situation of Veterans',
  'Reimagining Military Spouse Employment',
  'Support IVMF',
  'Alumni',
  'Community Services',
  'Community of Practice',
  'Community of Practice - Scholarships',
  'Community News',
  'AmericaServes',
  'AmericaServes Insights',
  'AmericaServes Locations',
  'Enhancing New Mexico Veteran Services',
  'PAServes',
  'Regional CoP',
  'SCServes',
  'SyracuseServes',
  'SyracuseServes - Contact',
  'SyracuseServes - Network Providers',
  'SyracuseServes - News & Events',
  'SyracuseServes - Request Assistance',
  'SyracuseServes - Veteran Benefits',
  'SyracuseServes - Veteran Resources',
  'TXServes: RGV',
  'Technical Assistance',
  'Texas Regional CoP',
  'WAServes',
  'Veterans for Public Office',
  'Data Insights & Tools',
  'V-START',
  'V-START Dashboards',
  'Data Philosophy',
  'Data Strategy',
  'National Survey of Military-Affiliated Entrepreneurs (NSMAE) Dashboard',
  'Buy Military-Owned Guide',
  'Apparel and Accessories',
  'Beer & Spirits',
  'Coffee',
  'Books',
  'Gifts',
  'Tactical, Safety & Fitness Products',
  'Services',
  'Self Care',
  'Hobbies, Sports & Games',
  'Buy Military-Owned Service Submission',
  'Buy Military-Owned Product Submission',
  'Volunteer Form',
  'Successful Life After Service',
  'Service Offerings',
] as $title) {
  foreach ($page_refs as $page_title => $page) {
    if (stripos($page_title, $title) !== false) {
    $selected_pages[$title] = $page;
    if (!empty($page['page_title'])) {
      $page_refs[$page['page_title']] = $page;
    }
    break;
  }
}
}

$showcase_source = $selected_pages['About IVMF'] ?? reset($content_pages);
$showcase_images = $showcase_source['images'] ?? [];
$showcase_sections = import_text_sections($showcase_source['main_html'] ?? '');
$showcase_cards = array_values(array_filter([
  $selected_pages['About IVMF'] ?? NULL,
  $selected_pages['Impact'] ?? NULL,
  $selected_pages['Why Syracuse University'] ?? NULL,
]));
$showcase_numbers = import_first_numbers($selected_pages['Impact']['main_text'] ?? ($showcase_source['main_text'] ?? ''));

$showcase_tree = [];
$showcase_tree[] = import_component_items($components, 'hero', [
  'eyebrow' => 'D\'Aniello IVMF',
  'heading' => $showcase_source['h1'] ?: $showcase_source['page_title'] ?: 'IVMF Canvas Wireup',
  'body' => import_excerpt($showcase_source['meta_description'] ?: ($showcase_source['main_text'] ?? '')),
  'link_text' => 'View source page',
  'link_url' => $showcase_source['final_url'] ?: $showcase_source['source_url'],
], 'Imported Hero', '10000000-0000-4000-8000-000000000001');

if (!empty($showcase_cards)) {
  $layout_uuid = '13000000-0000-4000-8000-000000000001';
  $showcase_tree[] = import_component_items($components, 'layout-container', [
    'heading' => 'Representative sections pulled from the crawl output.',
    'columns' => 3,
  ], 'Content Highlights', $layout_uuid);
  foreach ([1, 2, 3] as $index) {
    $source_page = $showcase_cards[$index - 1] ?? NULL;
    if (!$source_page) {
      continue;
    }
    $showcase_tree[] = import_component_items($components, 'card', [
      'number' => sprintf('%02d', $index),
      'heading' => $source_page['h1'] ?: $source_page['page_title'] ?: "Card {$index}",
      'content' => import_excerpt($source_page['main_text'] ?? '', 220),
      'link_text' => 'Open imported page',
      'link_url' => '/canvas-import/' . import_slug($source_page['h1'] ?: $source_page['page_title'] ?: "card-{$index}"),
    ], 'Card ' . $index, sprintf('13000000-0000-4000-8000-%012d', $index + 1), $layout_uuid, 'content');
  }
}

if ($showcase_numbers) {
  $showcase_tree[] = import_component_items($components, 'fact-stats', [
    'section_title' => 'Stats and Facts',
    'content_text_area' => 'Key figures from the crawl output.',
    'stat_1' => $showcase_numbers[0] ?? NULL,
    'stat_2' => $showcase_numbers[1] ?? NULL,
    'stat_3' => $showcase_numbers[2] ?? NULL,
    'stat_4' => $showcase_numbers[3] ?? NULL,
  ], 'Stats Summary', '14000000-0000-4000-8000-000000000001');
}

if (!empty($showcase_images)) {
  $showcase_tree[] = import_component_items($components, 'media-gallery', [
    'top_row_image_1' => import_image_payload($showcase_images[0] ?? NULL),
    'top_row_image_1_orientation' => 'left',
    'top_row_image_2' => import_image_payload($showcase_images[1] ?? NULL),
    'top_row_image_2_orientation' => 'landscape',
    'top_row_image_3' => import_image_payload($showcase_images[2] ?? NULL),
    'bottom_row_image_4' => import_image_payload($showcase_images[3] ?? NULL),
    'bottom_row_image_4_orientation' => 'full',
    'bottom_row_image_5' => import_image_payload($showcase_images[4] ?? NULL),
  ], 'Media Gallery', '14500000-0000-4000-8000-000000000001');
}

if ($showcase_cards) {
  $promo_image_source = $showcase_cards[0]['images'][0] ?? $showcase_images[0] ?? NULL;
  $showcase_tree[] = import_component_items($components, 'promo-banner', [
    'heading' => 'Featured content',
    'content' => import_excerpt($showcase_cards[0]['main_text'] ?? $showcase_source['main_text'] ?? '', 320),
    'image' => import_image_payload($promo_image_source ? [
      'url' => $promo_image_source['url'] ?? '',
      'alt' => $promo_image_source['alt'] ?? ($promo_image_source['title'] ?? ''),
    ] : NULL),
    'image_position' => 'right',
    'theme' => 'dark',
    'link_url' => '/canvas-import/' . import_slug($showcase_cards[0]['h1'] ?: $showcase_cards[0]['page_title'] ?: 'featured'),
    'link_text' => 'Read story',
    'accessible_link_text' => 'Open the featured story',
    'second_link_url' => '/canvas-import/component-map',
    'second_link_text' => 'See component map',
    'second_accessible_link_text' => 'Open the component map page',
  ], 'Promo Banner', '14600000-0000-4000-8000-000000000001');
}

if (!empty($selected_pages)) {
  $action_pages = array_values($selected_pages);
  $showcase_tree[] = import_component_items($components, 'action-bar', [
    'column_1_heading' => 'Find your Community',
    'column_1_description' => import_excerpt($action_pages[0]['main_text'] ?? '', 120),
    'column_1_link_text' => $action_pages[0]['page_title'] ?? 'Open page',
    'column_1_accessible_link_text' => 'Open ' . ($action_pages[0]['page_title'] ?? 'page'),
    'column_1_link_url' => '/canvas-import/' . import_slug($action_pages[0]['h1'] ?: $action_pages[0]['page_title'] ?: 'page-1'),
    'column_2_heading' => 'Connect With Alumni',
    'column_2_description' => import_excerpt($action_pages[1]['main_text'] ?? ($action_pages[0]['main_text'] ?? ''), 120),
    'column_2_link_text' => $action_pages[1]['page_title'] ?? 'Open page',
    'column_2_accessible_link_text' => 'Open ' . ($action_pages[1]['page_title'] ?? 'page'),
    'column_2_link_url' => '/canvas-import/' . import_slug($action_pages[1]['h1'] ?: $action_pages[1]['page_title'] ?: 'page-2'),
    'column_3_heading' => 'Attend Upcoming Events',
    'column_3_description' => import_excerpt($action_pages[2]['main_text'] ?? ($action_pages[0]['main_text'] ?? ''), 120),
    'column_3_link_text' => $action_pages[2]['page_title'] ?? 'Open page',
    'column_3_accessible_link_text' => 'Open ' . ($action_pages[2]['page_title'] ?? 'page'),
    'column_3_link_url' => '/canvas-import/' . import_slug($action_pages[2]['h1'] ?: $action_pages[2]['page_title'] ?: 'page-3'),
  ], 'Action Bar', '14700000-0000-4000-8000-000000000001');
}

if (!empty($showcase_cards)) {
  $showcase_tree[] = import_component_items($components, 'full-bleed-cards', [
    'heading' => 'Explore the main content areas',
    'text' => 'A full-bleed card set reconstructed from the imported pages.',
    'gradient_intensity' => 'medium',
    'card_1_image' => import_first_image_payload($showcase_cards[0]),
    'card_1_title' => $showcase_cards[0]['page_title'] ?? 'Card 1',
    'card_1_url' => '/canvas-import/' . import_slug($showcase_cards[0]['h1'] ?: $showcase_cards[0]['page_title'] ?: 'card-1'),
    'card_1_accessible_link_text' => 'Open ' . ($showcase_cards[0]['page_title'] ?? 'card 1'),
    'card_1_read_time' => 'Read more',
    'card_2_image' => import_first_image_payload($showcase_cards[1] ?? $showcase_cards[0]),
    'card_2_title' => $showcase_cards[1]['page_title'] ?? 'Card 2',
    'card_2_url' => '/canvas-import/' . import_slug($showcase_cards[1]['h1'] ?: $showcase_cards[1]['page_title'] ?: 'card-2'),
    'card_2_accessible_link_text' => 'Open ' . ($showcase_cards[1]['page_title'] ?? 'card 2'),
    'card_2_read_time' => 'Read more',
    'card_3_image' => import_first_image_payload($showcase_cards[2] ?? $showcase_cards[0]),
    'card_3_title' => $showcase_cards[2]['page_title'] ?? 'Card 3',
    'card_3_url' => '/canvas-import/' . import_slug($showcase_cards[2]['h1'] ?: $showcase_cards[2]['page_title'] ?: 'card-3'),
    'card_3_accessible_link_text' => 'Open ' . ($showcase_cards[2]['page_title'] ?? 'card 3'),
    'card_3_read_time' => 'Read more',
    'bottom_cta_text' => 'Browse imported pages',
    'bottom_cta_url' => '/canvas-import/component-map',
    'bottom_cta_accessible_link_text' => 'Browse imported pages and component map',
  ], 'Full-Bleed Cards', '14800000-0000-4000-8000-000000000001');
}

if (!empty($selected_pages)) {
  $showcase_tree[] = import_component_items($components, 'layout-container', [
    'heading' => 'Quick links into the imported pages',
    'columns' => 3,
  ], 'Quick Links Layout', '14900000-0000-4000-8000-000000000001');
  foreach (array_slice(array_values($selected_pages), 0, 3) as $index => $page) {
    $showcase_tree[] = import_component_items($components, 'list-item', [
      'heading' => $page['h1'] ?: ($page['page_title'] ?? 'Imported page'),
      'subheading' => $page['page_title'] ?? 'Imported page',
      'description' => import_excerpt($page['meta_description'] ?: ($page['main_text'] ?? ''), 160),
      'cta_style' => 'button',
      'link_text' => 'Open page',
      'accessible_link_text' => 'Open ' . ($page['page_title'] ?? 'imported page'),
      'link_url' => '/canvas-import/' . import_slug($page['h1'] ?: $page['page_title'] ?: 'page'),
      'image' => import_first_image_payload($page),
    ], 'List Item ' . ($index + 1), sprintf('14900000-0000-4000-8000-%012d', $index + 2), '14900000-0000-4000-8000-000000000001', 'content');
  }
}

$showcase_tree[] = import_component_items($components, 'accordion-container', [
  'heading' => 'How the reconstruction works',
], 'Reconstruction FAQ', '14950000-0000-4000-8000-000000000001');
$accordion_items = [
  [
    'title' => 'Discovery first',
    'content' => 'We let Canvas discover components from the enabled theme and then promote eligible SDCs into stored component entities.',
  ],
  [
    'title' => 'Content mapped from crawl output',
    'content' => 'Page titles, body text, images, testimonials, and people profiles are pulled from the crawl output and mapped into Canvas component trees.',
  ],
  [
    'title' => 'Structure preserved in Canvas pages',
    'content' => 'The imported content is saved as Canvas pages only, so we can recreate the live site’s component-driven structure without using Drupal nodes.',
  ],
];
foreach ($accordion_items as $index => $accordion_item) {
  $showcase_tree[] = import_component_items($components, 'accordion', $accordion_item, 'FAQ Item ' . ($index + 1), sprintf('14950000-0000-4000-8000-%012d', $index + 2), '14950000-0000-4000-8000-000000000001', 'accordion_items');
}

$showcase_tree[] = import_component_items($components, 'checkerboard-container', [], 'Canvas Pattern Sketch', '15000000-0000-4000-8000-000000000001');
foreach (array_slice($showcase_sections, 0, 2) as $index => $section) {
  $showcase_tree[] = import_component_items($components, 'checkerboard', [
    'eyebrow' => $section['heading'] ?: 'Section ' . ($index + 1),
    'heading' => $section['heading'] ?: 'Section ' . ($index + 1),
    'content' => import_excerpt($section['content'], 260),
  ], 'Section ' . ($index + 1), sprintf('15000000-0000-4000-8000-%012d', $index + 2), '15000000-0000-4000-8000-000000000001', 'checkerboard_items');
}

$testimonial_source = NULL;
if ($testimonials) {
  $first_testimonial = $testimonials[0];
  $source_urls = json_decode($first_testimonial['source_urls'] ?? '[]', true) ?: [];
  $testimonial_source = [
    'quote' => $first_testimonial['quote'] ?? '',
    'citation' => $first_testimonial['citation'] ?? '',
    'image_url' => $first_testimonial['image_url'] ?? '',
    'image_alt' => $first_testimonial['image_alt'] ?? '',
    'source' => $source_urls[0] ?? 'canvas-import',
  ];
}
if ($testimonial_source) {
  $showcase_tree[] = import_component_items($components, 'testimonial', [
    'quote' => '<p>' . htmlspecialchars(import_excerpt($testimonial_source['quote'], 320), ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</p>',
    'cite_name' => import_excerpt($testimonial_source['citation'], 120),
    'program' => 'Imported from the crawl output',
    'program_year' => "'22",
    'school' => 'Syracuse University',
    'workplace_name' => 'IVMF Sandbox',
    'theme' => 'dark',
    'image' => import_image_payload(['url' => $testimonial_source['image_url'], 'alt' => $testimonial_source['image_alt']]),
    'link_url' => '/canvas-import/testimonials',
    'link_text' => 'Call to Action',
    'accessible_link_text' => 'Read the imported testimonial repository',
  ], 'Testimonial', '19000000-0000-4000-8000-000000000001');
}

import_upsert_canvas_page(
  'IVMF Canvas Component Map',
  '/canvas-import/component-map',
  'Canvas-native reconstruction of the recovered IVMF component grammar using imported crawl data.',
  $showcase_tree
);

foreach ($selected_pages as $label => $source_page) {
  $tree = [];
  $tree[] = import_component_items($components, 'hero', [
    'eyebrow' => 'Imported Page',
    'heading' => $source_page['h1'] ?: $source_page['page_title'] ?: $label,
    'body' => import_excerpt($source_page['meta_description'] ?: ($source_page['main_text'] ?? '')),
    'link_text' => 'Open source',
    'link_url' => $source_page['final_url'] ?: $source_page['source_url'],
  ], 'Hero', '21000000-0000-4000-8000-000000000001');

  $images = $source_page['images'] ?? [];
  if ($images) {
    $tree[] = import_component_items($components, 'checkerboard-container', [], 'Image Highlights', '22000000-0000-4000-8000-000000000001');
    foreach (array_slice($images, 0, 2) as $image_index => $image) {
      $tree[] = import_component_items($components, 'checkerboard', [
        'eyebrow' => $image['alt'] ?: 'Image ' . ($image_index + 1),
        'heading' => $image['title'] ?: ($image['alt'] ?: 'Image ' . ($image_index + 1)),
        'content' => import_excerpt($source_page['main_text'] ?? '', 220),
      ], 'Image ' . ($image_index + 1), sprintf('22000000-0000-4000-8000-%012d', $image_index + 2), '22000000-0000-4000-8000-000000000001', 'checkerboard_items');
    }
  }

  $sections = import_text_sections($source_page['main_html'] ?? '');
  if (!empty($sections)) {
    $section_layout_uuid = sprintf('28100000-0000-4000-8000-%012d', $index + 1);
    $tree[] = import_component_items($components, 'layout-container', [
      'heading' => 'Section highlights',
      'columns' => 3,
    ], 'Section Highlights Layout', $section_layout_uuid);
    foreach (array_slice($sections, 0, 3) as $section_index => $section) {
      $tree[] = import_component_items($components, 'list-item', [
        'heading' => $section['heading'] ?: 'Section ' . ($section_index + 1),
        'subheading' => $label,
        'description' => import_excerpt($section['content'], 180),
        'cta_style' => 'text',
        'link_text' => 'Read source',
        'accessible_link_text' => 'Open the source page for ' . ($section['heading'] ?: 'this section'),
        'link_url' => $source_page['final_url'] ?: $source_page['source_url'],
      ], 'Section Teaser ' . ($section_index + 1), sprintf('28100000-0000-4000-8000-%012d', ($index * 10) + $section_index + 2), $section_layout_uuid, 'content');
    }
  }
  if (count($sections) >= 6 || stripos($label, 'FAQ') !== FALSE) {
    $accordion_layout_uuid = sprintf('28200000-0000-4000-8000-%012d', $index + 1);
    $tree[] = import_component_items($components, 'accordion-container', [
      'heading' => 'Page outline',
    ], 'Page Outline', $accordion_layout_uuid);
    foreach (array_slice($sections, 0, 4) as $section_index => $section) {
      $tree[] = import_component_items($components, 'accordion', [
        'title' => $section['heading'] ?: 'Section ' . ($section_index + 1),
        'content' => import_excerpt($section['content'], 500),
      ], 'Outline Item ' . ($section_index + 1), sprintf('28200000-0000-4000-8000-%012d', ($index * 10) + $section_index + 2), $accordion_layout_uuid, 'accordion_items');
    }
  }
  foreach (array_slice($sections, 0, 2) as $index => $section) {
    $tree[] = import_component_items($components, 'text-content', [
      'heading' => $section['heading'] ?: 'Section ' . ($index + 1),
      'content' => import_excerpt($section['content'], 400),
    ], 'Text Content', sprintf('23000000-0000-4000-8000-%012d', $index + 1));
  }

  $numbers = import_first_numbers($source_page['main_text'] ?? '');
  if ($numbers) {
    $tree[] = import_component_items($components, 'stats', [
      'section_title' => 'Stats and Facts',
      'content_text_area' => 'Key figures from the imported page content.',
      'stat_1' => $numbers[0] ?? NULL,
      'stat_2' => $numbers[1] ?? NULL,
      'stat_3' => $numbers[2] ?? NULL,
      'stat_4' => $numbers[3] ?? NULL,
    ], 'Stats', '24000000-0000-4000-8000-000000000001');
  }

  if (!empty($source_page['images'])) {
    $tree[] = import_component_items($components, 'media-gallery', [
      'top_row_image_1' => import_image_payload($source_page['images'][0] ?? NULL),
      'top_row_image_1_orientation' => 'left',
      'top_row_image_2' => import_image_payload($source_page['images'][1] ?? NULL),
      'top_row_image_2_orientation' => 'landscape',
      'bottom_row_image_4' => import_image_payload($source_page['images'][2] ?? NULL),
      'bottom_row_image_4_orientation' => 'half',
      'bottom_row_image_5' => import_image_payload($source_page['images'][3] ?? NULL),
    ], 'Media Gallery', '24500000-0000-4000-8000-000000000001');
  }

  if ($label === 'Impact' || $label === 'Team') {
    $tree[] = import_component_items($components, 'promo-banner', [
      'heading' => $source_page['h1'] ?: $label,
      'content' => import_excerpt($source_page['meta_description'] ?: ($source_page['main_text'] ?? ''), 220),
      'image' => import_first_image_payload($source_page),
      'image_position' => 'right',
      'theme' => 'dark',
      'link_url' => $source_page['final_url'] ?: $source_page['source_url'],
      'link_text' => 'View source',
      'accessible_link_text' => 'Open the source page',
      'second_link_url' => '/canvas-import/component-map',
      'second_link_text' => 'Open component map',
      'second_accessible_link_text' => 'Open the component map',
    ], 'Promo Banner', '24600000-0000-4000-8000-000000000001');
  }

  foreach ($source_page['testimonials'] ?? [] as $index => $testimonial) {
    $tree[] = import_component_items($components, 'testimonial', [
      'quote' => '<p>' . htmlspecialchars($testimonial['quote'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</p>',
      'cite_name' => import_excerpt($testimonial['citation'] ?? '', 100),
      'program' => 'Imported testimonial',
      'program_year' => '',
      'school' => '',
      'workplace_name' => '',
      'theme' => ($index % 2 === 0) ? 'dark' : 'light',
      'image' => import_image_payload(['url' => $testimonial['image_url'] ?? '', 'alt' => $testimonial['image_alt'] ?? '']),
      'link_url' => '/canvas-import/testimonials',
      'link_text' => 'Read more',
      'accessible_link_text' => 'Open the testimonial repository',
    ], 'Testimonial', sprintf('25000000-0000-4000-8000-%012d', $index + 1));
  }

  if (count($source_page['images'] ?? []) >= 3 && stripos($label, 'Support') === FALSE && stripos($label, 'Alumni') === FALSE) {
    $image_cards = array_slice($source_page['images'], 0, 3);
    $tree[] = import_component_items($components, 'full-bleed-cards', [
      'heading' => $source_page['h1'] ?: ($source_page['page_title'] ?? $label),
      'text' => 'Image-led highlights reconstructed from the imported page content.',
      'gradient_intensity' => 'medium',
      'card_1_image' => import_image_payload($image_cards[0] ?? NULL),
      'card_1_title' => ($image_cards[0]['title'] ?? $image_cards[0]['alt'] ?? 'Highlight 1'),
      'card_1_url' => $source_page['final_url'] ?: $source_page['source_url'],
      'card_1_accessible_link_text' => 'Open the source page',
      'card_1_read_time' => 'Read more',
      'card_2_image' => import_image_payload($image_cards[1] ?? NULL),
      'card_2_title' => ($image_cards[1]['title'] ?? $image_cards[1]['alt'] ?? 'Highlight 2'),
      'card_2_url' => $source_page['final_url'] ?: $source_page['source_url'],
      'card_2_accessible_link_text' => 'Open the source page',
      'card_2_read_time' => 'Read more',
      'card_3_image' => import_image_payload($image_cards[2] ?? NULL),
      'card_3_title' => ($image_cards[2]['title'] ?? $image_cards[2]['alt'] ?? 'Highlight 3'),
      'card_3_url' => $source_page['final_url'] ?: $source_page['source_url'],
      'card_3_accessible_link_text' => 'Open the source page',
      'card_3_read_time' => 'Read more',
      'bottom_cta_text' => 'Open source page',
      'bottom_cta_url' => $source_page['final_url'] ?: $source_page['source_url'],
      'bottom_cta_accessible_link_text' => 'Open the source page',
    ], 'Image Highlights Cards', '24650000-0000-4000-8000-000000000001');
  }

  if ($label === 'Team' && $people) {
    $layout_uuid = '26000000-0000-4000-8000-000000000001';
    $tree[] = import_component_items($components, 'layout-container', [
      'heading' => 'Directory',
      'columns' => 3,
    ], 'People Directory Layout', $layout_uuid);
    foreach (array_slice($people, 0, 6) as $index => $person) {
      $tree[] = import_component_items($components, 'card', [
        'number' => sprintf('%02d', $index + 1),
        'heading' => $person['name'] ?: 'Person',
        'content' => import_excerpt($person['title'] ?: $person['bio_text'] ?: ''),
        'link_text' => 'Open profile',
        'link_url' => '/canvas-import/people/' . import_slug($person['name'] ?: 'person'),
      ], 'People Card', sprintf('27000000-0000-4000-8000-%012d', $index + 1), $layout_uuid, 'content');
    }
  }

  if ($label === 'About IVMF') {
    $tree[] = import_component_items($components, 'action-bar', [
      'column_1_heading' => 'Learn About IVMF',
      'column_1_description' => import_excerpt($source_page['main_text'] ?? '', 120),
      'column_1_link_text' => 'About',
      'column_1_accessible_link_text' => 'Open About IVMF',
      'column_1_link_url' => $source_page['final_url'] ?: $source_page['source_url'],
      'column_2_heading' => 'See Programs',
      'column_2_description' => 'Browse the program offerings pulled from the site crawl.',
      'column_2_link_text' => 'Programs',
      'column_2_accessible_link_text' => 'Open programs page',
      'column_2_link_url' => '/canvas-import/' . import_slug(($selected_pages['Impact']['h1'] ?? $selected_pages['Impact']['page_title'] ?? 'impact')),
      'column_3_heading' => 'Read Testimonials',
      'column_3_description' => 'See the imported testimonial repository.',
      'column_3_link_text' => 'Testimonials',
      'column_3_accessible_link_text' => 'Open testimonials repository',
      'column_3_link_url' => '/canvas-import/testimonials',
    ], 'Action Bar', '27100000-0000-4000-8000-000000000001');
  }

  if (stripos($label, 'Programs') !== FALSE || stripos($label, 'Entrepreneurship') !== FALSE || stripos($label, 'Career Training') !== FALSE) {
    $program_links = [
      'Programs',
      'Career Training',
      'Entrepreneurship',
    ];
    $program_targets = array_values(array_filter(array_map(
      static fn(string $key): ?array => $selected_pages[$key] ?? NULL,
      $program_links
    )));
    if ($program_targets) {
      $tree[] = import_component_items($components, 'action-bar', [
        'column_1_heading' => $program_targets[0]['h1'] ?: ($program_targets[0]['page_title'] ?? 'Programs'),
        'column_1_description' => import_excerpt($program_targets[0]['main_text'] ?? '', 120),
        'column_1_link_text' => $program_targets[0]['page_title'] ?? 'Open page',
        'column_1_accessible_link_text' => 'Open ' . ($program_targets[0]['page_title'] ?? 'page'),
        'column_1_link_url' => '/canvas-import/' . import_slug($program_targets[0]['h1'] ?: $program_targets[0]['page_title'] ?: 'page-1'),
        'column_2_heading' => $program_targets[1]['h1'] ?: ($program_targets[1]['page_title'] ?? 'Career Training'),
        'column_2_description' => import_excerpt($program_targets[1]['main_text'] ?? '', 120),
        'column_2_link_text' => $program_targets[1]['page_title'] ?? 'Open page',
        'column_2_accessible_link_text' => 'Open ' . ($program_targets[1]['page_title'] ?? 'page'),
        'column_2_link_url' => '/canvas-import/' . import_slug($program_targets[1]['h1'] ?: $program_targets[1]['page_title'] ?: 'page-2'),
        'column_3_heading' => $program_targets[2]['h1'] ?: ($program_targets[2]['page_title'] ?? 'Entrepreneurship'),
        'column_3_description' => import_excerpt($program_targets[2]['main_text'] ?? '', 120),
        'column_3_link_text' => $program_targets[2]['page_title'] ?? 'Open page',
        'column_3_accessible_link_text' => 'Open ' . ($program_targets[2]['page_title'] ?? 'page'),
        'column_3_link_url' => '/canvas-import/' . import_slug($program_targets[2]['h1'] ?: $program_targets[2]['page_title'] ?: 'page-3'),
      ], 'Programs Action Bar', '27200000-0000-4000-8000-000000000001');
    }
  }

  if (stripos($label, 'Entrepreneurship') !== FALSE || stripos($label, 'EBV') !== FALSE || str_contains($label, 'V-WISE') || str_contains($label, 'Vet100') || stripos($label, 'Veteran EDGE') !== FALSE || stripos($label, 'STRIVE') !== FALSE) {
    $entrepreneurship_targets = array_values(array_filter([
      $selected_pages['Entrepreneurship'] ?? NULL,
      $selected_pages['Getting Started'] ?? NULL,
      $selected_pages['Ideation'] ?? NULL,
      $selected_pages['Boots to Business'] ?? NULL,
      $selected_pages['Startup Training Resources to Inspire Veteran Entrepreneurship (STRIVE)'] ?? NULL,
      $selected_pages['Start Up'] ?? NULL,
      $selected_pages['Veteran Women Igniting the Spirit of Entrepreneurship (V-WISE)'] ?? NULL,
      $selected_pages['Growth'] ?? NULL,
      $selected_pages['Veteran EDGE'] ?? NULL,
      $selected_pages['Vet100'] ?? NULL,
      $selected_pages['Entrepreneurship Bootcamp for Veterans (EBV)'] ?? NULL,
    ]));
    if ($entrepreneurship_targets) {
      $tree[] = import_component_items($components, 'action-bar', [
        'column_1_heading' => $entrepreneurship_targets[0]['h1'] ?: ($entrepreneurship_targets[0]['page_title'] ?? 'Entrepreneurship'),
        'column_1_description' => import_excerpt($entrepreneurship_targets[0]['main_text'] ?? '', 120),
        'column_1_link_text' => $entrepreneurship_targets[0]['page_title'] ?? 'Open page',
        'column_1_accessible_link_text' => 'Open ' . ($entrepreneurship_targets[0]['page_title'] ?? 'page'),
        'column_1_link_url' => '/canvas-import/' . import_slug($entrepreneurship_targets[0]['h1'] ?: $entrepreneurship_targets[0]['page_title'] ?: 'page-1'),
        'column_2_heading' => $entrepreneurship_targets[1]['h1'] ?: ($entrepreneurship_targets[1]['page_title'] ?? 'Getting Started'),
        'column_2_description' => import_excerpt($entrepreneurship_targets[1]['main_text'] ?? '', 120),
        'column_2_link_text' => $entrepreneurship_targets[1]['page_title'] ?? 'Open page',
        'column_2_accessible_link_text' => 'Open ' . ($entrepreneurship_targets[1]['page_title'] ?? 'page'),
        'column_2_link_url' => '/canvas-import/' . import_slug($entrepreneurship_targets[1]['h1'] ?: $entrepreneurship_targets[1]['page_title'] ?: 'page-2'),
        'column_3_heading' => $entrepreneurship_targets[2]['h1'] ?: ($entrepreneurship_targets[2]['page_title'] ?? 'Ideation'),
        'column_3_description' => import_excerpt($entrepreneurship_targets[2]['main_text'] ?? '', 120),
        'column_3_link_text' => $entrepreneurship_targets[2]['page_title'] ?? 'Open page',
        'column_3_accessible_link_text' => 'Open ' . ($entrepreneurship_targets[2]['page_title'] ?? 'page'),
        'column_3_link_url' => '/canvas-import/' . import_slug($entrepreneurship_targets[2]['h1'] ?: $entrepreneurship_targets[2]['page_title'] ?: 'page-3'),
      ], 'Entrepreneurship Action Bar', '27250000-0000-4000-8000-000000000001');
    }
  }

  if (stripos($label, 'Career') !== FALSE || stripos($label, 'O2O') !== FALSE || stripos($label, 'Employer') !== FALSE || stripos($label, 'FAQ') !== FALSE || stripos($label, 'Public Office') !== FALSE) {
    $career_targets = array_values(array_filter([
      $selected_pages['Career Training'] ?? NULL,
      $selected_pages['Onward to Opportunity Application'] ?? NULL,
      $selected_pages['Career Preparation'] ?? NULL,
      $selected_pages['Employer Partners'] ?? NULL,
      $selected_pages['O2O Locations'] ?? NULL,
      $selected_pages['FAQ'] ?? NULL,
      $selected_pages['Veterans for Public Office'] ?? NULL,
    ]));
    if ($career_targets) {
      $tree[] = import_component_items($components, 'action-bar', [
        'column_1_heading' => $career_targets[0]['h1'] ?: ($career_targets[0]['page_title'] ?? 'Career Training'),
        'column_1_description' => import_excerpt($career_targets[0]['main_text'] ?? '', 120),
        'column_1_link_text' => $career_targets[0]['page_title'] ?? 'Open page',
        'column_1_accessible_link_text' => 'Open ' . ($career_targets[0]['page_title'] ?? 'page'),
        'column_1_link_url' => '/canvas-import/' . import_slug($career_targets[0]['h1'] ?: $career_targets[0]['page_title'] ?: 'page-1'),
        'column_2_heading' => $career_targets[1]['h1'] ?: ($career_targets[1]['page_title'] ?? 'Career Preparation'),
        'column_2_description' => import_excerpt($career_targets[1]['main_text'] ?? '', 120),
        'column_2_link_text' => $career_targets[1]['page_title'] ?? 'Open page',
        'column_2_accessible_link_text' => 'Open ' . ($career_targets[1]['page_title'] ?? 'page'),
        'column_2_link_url' => '/canvas-import/' . import_slug($career_targets[1]['h1'] ?: $career_targets[1]['page_title'] ?: 'page-2'),
        'column_3_heading' => $career_targets[2]['h1'] ?: ($career_targets[2]['page_title'] ?? 'Employer Partners'),
        'column_3_description' => import_excerpt($career_targets[2]['main_text'] ?? '', 120),
        'column_3_link_text' => $career_targets[2]['page_title'] ?? 'Open page',
        'column_3_accessible_link_text' => 'Open ' . ($career_targets[2]['page_title'] ?? 'page'),
        'column_3_link_url' => '/canvas-import/' . import_slug($career_targets[2]['h1'] ?: $career_targets[2]['page_title'] ?: 'page-3'),
      ], 'Career Action Bar', '27600000-0000-4000-8000-000000000001');
    }
  }

  if (stripos($label, 'Ambassador') !== FALSE || stripos($label, 'CEOcircle') !== FALSE || stripos($label, 'Founders Lab') !== FALSE || stripos($label, 'Thank You') !== FALSE) {
    $ambassador_targets = array_values(array_filter([
      $selected_pages['Ambassador Program'] ?? NULL,
      $selected_pages['Ambassador Communities'] ?? NULL,
      $selected_pages['CEOcircle'] ?? NULL,
      $selected_pages['Military Founders Lab'] ?? NULL,
      $selected_pages['Bunker Labs Ambassador - Thank You'] ?? NULL,
    ]));
    if ($ambassador_targets) {
      $tree[] = import_component_items($components, 'action-bar', [
        'column_1_heading' => $ambassador_targets[0]['h1'] ?: ($ambassador_targets[0]['page_title'] ?? 'Ambassador Program'),
        'column_1_description' => import_excerpt($ambassador_targets[0]['main_text'] ?? '', 120),
        'column_1_link_text' => $ambassador_targets[0]['page_title'] ?? 'Open page',
        'column_1_accessible_link_text' => 'Open ' . ($ambassador_targets[0]['page_title'] ?? 'page'),
        'column_1_link_url' => '/canvas-import/' . import_slug($ambassador_targets[0]['h1'] ?: $ambassador_targets[0]['page_title'] ?: 'page-1'),
        'column_2_heading' => $ambassador_targets[1]['h1'] ?: ($ambassador_targets[1]['page_title'] ?? 'Ambassador Communities'),
        'column_2_description' => import_excerpt($ambassador_targets[1]['main_text'] ?? '', 120),
        'column_2_link_text' => $ambassador_targets[1]['page_title'] ?? 'Open page',
        'column_2_accessible_link_text' => 'Open ' . ($ambassador_targets[1]['page_title'] ?? 'page'),
        'column_2_link_url' => '/canvas-import/' . import_slug($ambassador_targets[1]['h1'] ?: $ambassador_targets[1]['page_title'] ?: 'page-2'),
        'column_3_heading' => $ambassador_targets[2]['h1'] ?: ($ambassador_targets[2]['page_title'] ?? 'CEOcircle'),
        'column_3_description' => import_excerpt($ambassador_targets[2]['main_text'] ?? '', 120),
        'column_3_link_text' => $ambassador_targets[2]['page_title'] ?? 'Open page',
        'column_3_accessible_link_text' => 'Open ' . ($ambassador_targets[2]['page_title'] ?? 'page'),
        'column_3_link_url' => '/canvas-import/' . import_slug($ambassador_targets[2]['h1'] ?: $ambassador_targets[2]['page_title'] ?: 'page-3'),
      ], 'Ambassador Action Bar', '28300000-0000-4000-8000-000000000001');
    }
  }

  if (stripos($label, 'Coalition') !== FALSE || stripos($label, 'CVOB') !== FALSE || stripos($label, 'Center of Excellence') !== FALSE) {
    $cvob_targets = array_values(array_filter([
      $selected_pages['Coalition for Veteran Owned Business (CVOB)'] ?? NULL,
      $selected_pages['CVOB Military Entrepreneurship Forum (MEF)'] ?? NULL,
      $selected_pages['CVOB Mission to Marketplace (M2M)'] ?? NULL,
      $selected_pages['Center of Excellence for Veteran Entrepreneurship (COE)'] ?? NULL,
      $selected_pages['Our Programs'] ?? NULL,
    ]));
    if ($cvob_targets) {
      $tree[] = import_component_items($components, 'action-bar', [
        'column_1_heading' => $cvob_targets[0]['h1'] ?: ($cvob_targets[0]['page_title'] ?? 'Coalition for Veteran Owned Business'),
        'column_1_description' => import_excerpt($cvob_targets[0]['main_text'] ?? '', 120),
        'column_1_link_text' => $cvob_targets[0]['page_title'] ?? 'Open page',
        'column_1_accessible_link_text' => 'Open ' . ($cvob_targets[0]['page_title'] ?? 'page'),
        'column_1_link_url' => '/canvas-import/' . import_slug($cvob_targets[0]['h1'] ?: $cvob_targets[0]['page_title'] ?: 'page-1'),
        'column_2_heading' => $cvob_targets[1]['h1'] ?: ($cvob_targets[1]['page_title'] ?? 'CVOB MEF'),
        'column_2_description' => import_excerpt($cvob_targets[1]['main_text'] ?? '', 120),
        'column_2_link_text' => $cvob_targets[1]['page_title'] ?? 'Open page',
        'column_2_accessible_link_text' => 'Open ' . ($cvob_targets[1]['page_title'] ?? 'page'),
        'column_2_link_url' => '/canvas-import/' . import_slug($cvob_targets[1]['h1'] ?: $cvob_targets[1]['page_title'] ?: 'page-2'),
        'column_3_heading' => $cvob_targets[2]['h1'] ?: ($cvob_targets[2]['page_title'] ?? 'CVOB M2M'),
        'column_3_description' => import_excerpt($cvob_targets[2]['main_text'] ?? '', 120),
        'column_3_link_text' => $cvob_targets[2]['page_title'] ?? 'Open page',
        'column_3_accessible_link_text' => 'Open ' . ($cvob_targets[2]['page_title'] ?? 'page'),
        'column_3_link_url' => '/canvas-import/' . import_slug($cvob_targets[2]['h1'] ?: $cvob_targets[2]['page_title'] ?: 'page-3'),
      ], 'CVOB Action Bar', '28320000-0000-4000-8000-000000000001');
    }
  }

  if (stripos($label, 'Application') !== FALSE || stripos($label, 'Accelerate') !== FALSE || stripos($label, 'Boots to Business') !== FALSE || stripos($label, 'STRIVE') !== FALSE) {
    $application_targets = array_values(array_filter([
      $selected_pages['Entrepreneurship'] ?? NULL,
      $selected_pages['Boots to Business'] ?? NULL,
      $selected_pages['Startup Training Resources to Inspire Veteran Entrepreneurship (STRIVE)'] ?? NULL,
      $selected_pages['Entrepreneurship Bootcamp for Veterans (EBV)'] ?? NULL,
      $selected_pages['Entrepreneurship Bootcamp for Veterans Accelerate'] ?? NULL,
      $selected_pages['EBV Accelerate Application'] ?? NULL,
      $selected_pages['EBV-F Application'] ?? NULL,
    ]));
    if ($application_targets) {
      $tree[] = import_component_items($components, 'action-bar', [
        'column_1_heading' => $application_targets[0]['h1'] ?: ($application_targets[0]['page_title'] ?? 'Entrepreneurship'),
        'column_1_description' => import_excerpt($application_targets[0]['main_text'] ?? '', 120),
        'column_1_link_text' => $application_targets[0]['page_title'] ?? 'Open page',
        'column_1_accessible_link_text' => 'Open ' . ($application_targets[0]['page_title'] ?? 'page'),
        'column_1_link_url' => '/canvas-import/' . import_slug($application_targets[0]['h1'] ?: $application_targets[0]['page_title'] ?: 'page-1'),
        'column_2_heading' => $application_targets[1]['h1'] ?: ($application_targets[1]['page_title'] ?? 'Boots to Business'),
        'column_2_description' => import_excerpt($application_targets[1]['main_text'] ?? '', 120),
        'column_2_link_text' => $application_targets[1]['page_title'] ?? 'Open page',
        'column_2_accessible_link_text' => 'Open ' . ($application_targets[1]['page_title'] ?? 'page'),
        'column_2_link_url' => '/canvas-import/' . import_slug($application_targets[1]['h1'] ?: $application_targets[1]['page_title'] ?: 'page-2'),
        'column_3_heading' => $application_targets[2]['h1'] ?: ($application_targets[2]['page_title'] ?? 'STRIVE'),
        'column_3_description' => import_excerpt($application_targets[2]['main_text'] ?? '', 120),
        'column_3_link_text' => $application_targets[2]['page_title'] ?? 'Open page',
        'column_3_accessible_link_text' => 'Open ' . ($application_targets[2]['page_title'] ?? 'page'),
        'column_3_link_url' => '/canvas-import/' . import_slug($application_targets[2]['h1'] ?: $application_targets[2]['page_title'] ?: 'page-3'),
      ], 'Application Action Bar', '28350000-0000-4000-8000-000000000001');
    }
  }

  if (stripos($label, 'O2O') !== FALSE || stripos($label, 'Locations') !== FALSE) {
    $location_targets = array_values(array_filter([
      $selected_pages['O2O Locations'] ?? NULL,
      $selected_pages['The Onward to Opportunity Program for the Florida Military Communities'] ?? NULL,
      $selected_pages['Southern California'] ?? NULL,
      $selected_pages['Joint Base San Antonio'] ?? NULL,
      $selected_pages['Fort Carson'] ?? NULL,
      $selected_pages['National Capital Region'] ?? NULL,
    ]));
    if ($location_targets) {
      $tree[] = import_component_items($components, 'action-bar', [
        'column_1_heading' => $location_targets[0]['h1'] ?: ($location_targets[0]['page_title'] ?? 'O2O Locations'),
        'column_1_description' => import_excerpt($location_targets[0]['main_text'] ?? '', 120),
        'column_1_link_text' => $location_targets[0]['page_title'] ?? 'Open page',
        'column_1_accessible_link_text' => 'Open ' . ($location_targets[0]['page_title'] ?? 'page'),
        'column_1_link_url' => '/canvas-import/' . import_slug($location_targets[0]['h1'] ?: $location_targets[0]['page_title'] ?: 'page-1'),
        'column_2_heading' => $location_targets[1]['h1'] ?: ($location_targets[1]['page_title'] ?? 'Florida Military Communities'),
        'column_2_description' => import_excerpt($location_targets[1]['main_text'] ?? '', 120),
        'column_2_link_text' => $location_targets[1]['page_title'] ?? 'Open page',
        'column_2_accessible_link_text' => 'Open ' . ($location_targets[1]['page_title'] ?? 'page'),
        'column_2_link_url' => '/canvas-import/' . import_slug($location_targets[1]['h1'] ?: $location_targets[1]['page_title'] ?: 'page-2'),
        'column_3_heading' => $location_targets[2]['h1'] ?: ($location_targets[2]['page_title'] ?? 'Southern California'),
        'column_3_description' => import_excerpt($location_targets[2]['main_text'] ?? '', 120),
        'column_3_link_text' => $location_targets[2]['page_title'] ?? 'Open page',
        'column_3_accessible_link_text' => 'Open ' . ($location_targets[2]['page_title'] ?? 'page'),
        'column_3_link_url' => '/canvas-import/' . import_slug($location_targets[2]['h1'] ?: $location_targets[2]['page_title'] ?: 'page-3'),
      ], 'Location Action Bar', '27800000-0000-4000-8000-000000000001');
    }
  }

  if (stripos($label, 'Research') !== FALSE || stripos($label, 'Policy') !== FALSE || str_contains($label, 'Data Insights') || str_contains($label, 'National Survey') || str_contains($label, 'Apply for')) {
    $research_targets = array_values(array_filter([
      $selected_pages['Research + Analytics + Policy'] ?? NULL,
      $selected_pages['Research Projects'] ?? NULL,
      $selected_pages['Research Reviews'] ?? NULL,
      $selected_pages['Current Projects'] ?? NULL,
      $selected_pages['Past Projects'] ?? NULL,
      $selected_pages['National Survey of Military-Affiliated Entrepreneurs'] ?? NULL,
      $selected_pages['National Survey of Military-Affiliated Entrepreneurs Toolkit - 2024'] ?? NULL,
    ]));
    if ($research_targets) {
      $tree[] = import_component_items($components, 'action-bar', [
        'column_1_heading' => $research_targets[0]['h1'] ?: ($research_targets[0]['page_title'] ?? 'Research'),
        'column_1_description' => import_excerpt($research_targets[0]['main_text'] ?? '', 120),
        'column_1_link_text' => $research_targets[0]['page_title'] ?? 'Open page',
        'column_1_accessible_link_text' => 'Open ' . ($research_targets[0]['page_title'] ?? 'page'),
        'column_1_link_url' => '/canvas-import/' . import_slug($research_targets[0]['h1'] ?: $research_targets[0]['page_title'] ?: 'page-1'),
        'column_2_heading' => $research_targets[1]['h1'] ?: ($research_targets[1]['page_title'] ?? 'Research Projects'),
        'column_2_description' => import_excerpt($research_targets[1]['main_text'] ?? '', 120),
        'column_2_link_text' => $research_targets[1]['page_title'] ?? 'Open page',
        'column_2_accessible_link_text' => 'Open ' . ($research_targets[1]['page_title'] ?? 'page'),
        'column_2_link_url' => '/canvas-import/' . import_slug($research_targets[1]['h1'] ?: $research_targets[1]['page_title'] ?: 'page-2'),
        'column_3_heading' => $research_targets[2]['h1'] ?: ($research_targets[2]['page_title'] ?? 'Research Reviews'),
        'column_3_description' => import_excerpt($research_targets[2]['main_text'] ?? '', 120),
        'column_3_link_text' => $research_targets[2]['page_title'] ?? 'Open page',
        'column_3_accessible_link_text' => 'Open ' . ($research_targets[2]['page_title'] ?? 'page'),
        'column_3_link_url' => '/canvas-import/' . import_slug($research_targets[2]['h1'] ?: $research_targets[2]['page_title'] ?: 'page-3'),
      ], 'Research Action Bar', '27700000-0000-4000-8000-000000000001');
    }
  }

  if (stripos($label, 'Research') !== FALSE || stripos($label, 'Policy') !== FALSE) {
    $tree[] = import_component_items($components, 'promo-banner', [
      'heading' => $source_page['h1'] ?: $label,
      'content' => import_excerpt($source_page['meta_description'] ?: ($source_page['main_text'] ?? ''), 220),
      'image' => import_first_image_payload($source_page),
      'image_position' => 'left',
      'theme' => 'light',
      'link_url' => $source_page['final_url'] ?: $source_page['source_url'],
      'link_text' => 'Read more',
      'accessible_link_text' => 'Read more about ' . ($source_page['page_title'] ?? $label),
      'second_link_url' => '/canvas-import/component-map',
      'second_link_text' => 'Component map',
      'second_accessible_link_text' => 'Open component map',
    ], 'Research Promo Banner', '27300000-0000-4000-8000-000000000001');
  }

  if (stripos($label, 'Evaluation') !== FALSE || stripos($label, 'Analytics') !== FALSE) {
    $evaluation_targets = array_values(array_filter([
      $selected_pages['Evaluation & Analytics'] ?? NULL,
      $selected_pages['Current Projects'] ?? NULL,
      $selected_pages['Past Projects'] ?? NULL,
      $selected_pages['Research Projects'] ?? NULL,
    ]));
    if ($evaluation_targets) {
      $tree[] = import_component_items($components, 'action-bar', [
        'column_1_heading' => $evaluation_targets[0]['h1'] ?: ($evaluation_targets[0]['page_title'] ?? 'Evaluation & Analytics'),
        'column_1_description' => import_excerpt($evaluation_targets[0]['main_text'] ?? '', 120),
        'column_1_link_text' => $evaluation_targets[0]['page_title'] ?? 'Open page',
        'column_1_accessible_link_text' => 'Open ' . ($evaluation_targets[0]['page_title'] ?? 'page'),
        'column_1_link_url' => '/canvas-import/' . import_slug($evaluation_targets[0]['h1'] ?: $evaluation_targets[0]['page_title'] ?: 'page-1'),
        'column_2_heading' => $evaluation_targets[1]['h1'] ?: ($evaluation_targets[1]['page_title'] ?? 'Current Projects'),
        'column_2_description' => import_excerpt($evaluation_targets[1]['main_text'] ?? '', 120),
        'column_2_link_text' => $evaluation_targets[1]['page_title'] ?? 'Open page',
        'column_2_accessible_link_text' => 'Open ' . ($evaluation_targets[1]['page_title'] ?? 'page'),
        'column_2_link_url' => '/canvas-import/' . import_slug($evaluation_targets[1]['h1'] ?: $evaluation_targets[1]['page_title'] ?: 'page-2'),
        'column_3_heading' => $evaluation_targets[2]['h1'] ?: ($evaluation_targets[2]['page_title'] ?? 'Past Projects'),
        'column_3_description' => import_excerpt($evaluation_targets[2]['main_text'] ?? '', 120),
        'column_3_link_text' => $evaluation_targets[2]['page_title'] ?? 'Open page',
        'column_3_accessible_link_text' => 'Open ' . ($evaluation_targets[2]['page_title'] ?? 'page'),
        'column_3_link_url' => '/canvas-import/' . import_slug($evaluation_targets[2]['h1'] ?: $evaluation_targets[2]['page_title'] ?: 'page-3'),
      ], 'Evaluation Action Bar', '27750000-0000-4000-8000-000000000001');
    }
  }

  if (stripos($label, 'Support') !== FALSE || stripos($label, 'Alumni') !== FALSE || str_contains($label, 'Life After Service')) {
    $support_targets = array_values(array_filter([
      $selected_pages['Alumni'] ?? NULL,
      $selected_pages['Support IVMF'] ?? NULL,
      $selected_pages['Successful Life After Service'] ?? NULL,
    ]));
    if ($support_targets) {
      $tree[] = import_component_items($components, 'full-bleed-cards', [
        'heading' => $source_page['h1'] ?: $label,
        'text' => 'Support and alumni pathways from the imported site structure.',
        'gradient_intensity' => 'high',
        'card_1_image' => import_first_image_payload($support_targets[0]),
        'card_1_title' => $support_targets[0]['page_title'] ?? 'Support',
        'card_1_url' => '/canvas-import/' . import_slug($support_targets[0]['h1'] ?: $support_targets[0]['page_title'] ?: 'support'),
        'card_1_accessible_link_text' => 'Open ' . ($support_targets[0]['page_title'] ?? 'support page'),
        'card_1_read_time' => 'Read more',
        'card_2_image' => import_first_image_payload($support_targets[1] ?? $support_targets[0]),
        'card_2_title' => $support_targets[1]['page_title'] ?? 'Alumni',
        'card_2_url' => '/canvas-import/' . import_slug($support_targets[1]['h1'] ?: $support_targets[1]['page_title'] ?: 'alumni'),
        'card_2_accessible_link_text' => 'Open ' . ($support_targets[1]['page_title'] ?? 'alumni page'),
        'card_2_read_time' => 'Read more',
        'card_3_image' => import_first_image_payload($support_targets[2] ?? $support_targets[0]),
        'card_3_title' => $support_targets[2]['page_title'] ?? 'Service',
        'card_3_url' => '/canvas-import/' . import_slug($support_targets[2]['h1'] ?: $support_targets[2]['page_title'] ?: 'service'),
        'card_3_accessible_link_text' => 'Open ' . ($support_targets[2]['page_title'] ?? 'service page'),
        'card_3_read_time' => 'Read more',
        'bottom_cta_text' => 'Explore support pathways',
        'bottom_cta_url' => '/canvas-import/component-map',
        'bottom_cta_accessible_link_text' => 'Explore support pathways and component map',
      ], 'Support Full-Bleed Cards', '27400000-0000-4000-8000-000000000001');
    }
  }

  if (stripos($label, 'Support IVMF') !== FALSE || stripos($label, 'Alumni') !== FALSE) {
    $support_links = array_values(array_filter([
      $selected_pages['Support IVMF'] ?? NULL,
      $selected_pages['Alumni'] ?? NULL,
      $selected_pages['Successful Life After Service'] ?? NULL,
      $selected_pages['Community Services'] ?? NULL,
    ]));
    if ($support_links) {
      $tree[] = import_component_items($components, 'action-bar', [
        'column_1_heading' => $support_links[0]['h1'] ?: ($support_links[0]['page_title'] ?? 'Support IVMF'),
        'column_1_description' => import_excerpt($support_links[0]['main_text'] ?? '', 120),
        'column_1_link_text' => $support_links[0]['page_title'] ?? 'Open page',
        'column_1_accessible_link_text' => 'Open ' . ($support_links[0]['page_title'] ?? 'page'),
        'column_1_link_url' => '/canvas-import/' . import_slug($support_links[0]['h1'] ?: $support_links[0]['page_title'] ?: 'page-1'),
        'column_2_heading' => $support_links[1]['h1'] ?: ($support_links[1]['page_title'] ?? 'Alumni'),
        'column_2_description' => import_excerpt($support_links[1]['main_text'] ?? '', 120),
        'column_2_link_text' => $support_links[1]['page_title'] ?? 'Open page',
        'column_2_accessible_link_text' => 'Open ' . ($support_links[1]['page_title'] ?? 'page'),
        'column_2_link_url' => '/canvas-import/' . import_slug($support_links[1]['h1'] ?: $support_links[1]['page_title'] ?: 'page-2'),
        'column_3_heading' => $support_links[2]['h1'] ?: ($support_links[2]['page_title'] ?? 'Successful Life After Service'),
        'column_3_description' => import_excerpt($support_links[2]['main_text'] ?? '', 120),
        'column_3_link_text' => $support_links[2]['page_title'] ?? 'Open page',
        'column_3_accessible_link_text' => 'Open ' . ($support_links[2]['page_title'] ?? 'page'),
        'column_3_link_url' => '/canvas-import/' . import_slug($support_links[2]['h1'] ?: $support_links[2]['page_title'] ?: 'page-3'),
      ], 'Support Action Bar', '28400000-0000-4000-8000-000000000001');
    }
  }

  if (stripos($label, 'Data') !== FALSE || str_contains($label, 'V-START') || str_contains($label, 'National Survey')) {
    $data_targets = array_values(array_filter([
      $selected_pages['Data Insights & Tools'] ?? NULL,
      $selected_pages['Data Philosophy'] ?? NULL,
      $selected_pages['Data Strategy'] ?? NULL,
      $selected_pages['V-START'] ?? NULL,
      $selected_pages['V-START Dashboards'] ?? NULL,
      $selected_pages['National Survey of Military-Affiliated Entrepreneurs (NSMAE) Dashboard'] ?? NULL,
      $selected_pages['National Survey of Military-Affiliated Entrepreneurs'] ?? NULL,
    ]));
    if ($data_targets) {
      $tree[] = import_component_items($components, 'action-bar', [
        'column_1_heading' => $data_targets[0]['h1'] ?: ($data_targets[0]['page_title'] ?? 'Data Insights'),
        'column_1_description' => import_excerpt($data_targets[0]['main_text'] ?? '', 120),
        'column_1_link_text' => $data_targets[0]['page_title'] ?? 'Open page',
        'column_1_accessible_link_text' => 'Open ' . ($data_targets[0]['page_title'] ?? 'page'),
        'column_1_link_url' => '/canvas-import/' . import_slug($data_targets[0]['h1'] ?: $data_targets[0]['page_title'] ?: 'page-1'),
        'column_2_heading' => $data_targets[1]['h1'] ?: ($data_targets[1]['page_title'] ?? 'Data Philosophy'),
        'column_2_description' => import_excerpt($data_targets[1]['main_text'] ?? '', 120),
        'column_2_link_text' => $data_targets[1]['page_title'] ?? 'Open page',
        'column_2_accessible_link_text' => 'Open ' . ($data_targets[1]['page_title'] ?? 'page'),
        'column_2_link_url' => '/canvas-import/' . import_slug($data_targets[1]['h1'] ?: $data_targets[1]['page_title'] ?: 'page-2'),
        'column_3_heading' => $data_targets[2]['h1'] ?: ($data_targets[2]['page_title'] ?? 'Data Strategy'),
        'column_3_description' => import_excerpt($data_targets[2]['main_text'] ?? '', 120),
        'column_3_link_text' => $data_targets[2]['page_title'] ?? 'Open page',
        'column_3_accessible_link_text' => 'Open ' . ($data_targets[2]['page_title'] ?? 'page'),
        'column_3_link_url' => '/canvas-import/' . import_slug($data_targets[2]['h1'] ?: $data_targets[2]['page_title'] ?: 'page-3'),
      ], 'Data Action Bar', '27500000-0000-4000-8000-000000000001');
    }
  }

  if (stripos($label, 'Community') !== FALSE || stripos($label, 'News') !== FALSE || stripos($label, 'Practice') !== FALSE || stripos($label, 'Serves') !== FALSE || stripos($label, 'Technical Assistance') !== FALSE || stripos($label, 'Regional CoP') !== FALSE || stripos($label, 'Service Offerings') !== FALSE) {
    $community_targets = array_values(array_filter([
      $selected_pages['Community of Practice'] ?? NULL,
      $selected_pages['Community News'] ?? NULL,
      $selected_pages['Community Services'] ?? NULL,
      $selected_pages['Support IVMF'] ?? NULL,
      $selected_pages['AmericaServes'] ?? NULL,
      $selected_pages['SyracuseServes'] ?? NULL,
      $selected_pages['Regional CoP'] ?? NULL,
    ]));
    if ($community_targets) {
      $tree[] = import_component_items($components, 'action-bar', [
        'column_1_heading' => $community_targets[0]['h1'] ?: ($community_targets[0]['page_title'] ?? 'Community of Practice'),
        'column_1_description' => import_excerpt($community_targets[0]['main_text'] ?? '', 120),
        'column_1_link_text' => $community_targets[0]['page_title'] ?? 'Open page',
        'column_1_accessible_link_text' => 'Open ' . ($community_targets[0]['page_title'] ?? 'page'),
        'column_1_link_url' => '/canvas-import/' . import_slug($community_targets[0]['h1'] ?: $community_targets[0]['page_title'] ?: 'page-1'),
        'column_2_heading' => $community_targets[1]['h1'] ?: ($community_targets[1]['page_title'] ?? 'Community News'),
        'column_2_description' => import_excerpt($community_targets[1]['main_text'] ?? '', 120),
        'column_2_link_text' => $community_targets[1]['page_title'] ?? 'Open page',
        'column_2_accessible_link_text' => 'Open ' . ($community_targets[1]['page_title'] ?? 'page'),
        'column_2_link_url' => '/canvas-import/' . import_slug($community_targets[1]['h1'] ?: $community_targets[1]['page_title'] ?: 'page-2'),
        'column_3_heading' => $community_targets[2]['h1'] ?: ($community_targets[2]['page_title'] ?? 'Community Services'),
        'column_3_description' => import_excerpt($community_targets[2]['main_text'] ?? '', 120),
        'column_3_link_text' => $community_targets[2]['page_title'] ?? 'Open page',
        'column_3_accessible_link_text' => 'Open ' . ($community_targets[2]['page_title'] ?? 'page'),
        'column_3_link_url' => '/canvas-import/' . import_slug($community_targets[2]['h1'] ?: $community_targets[2]['page_title'] ?: 'page-3'),
      ], 'Community Action Bar', '27900000-0000-4000-8000-000000000001');
    }
  }

  if (stripos($label, 'About') !== FALSE || stripos($label, 'History') !== FALSE || stripos($label, 'Partners') !== FALSE || str_contains($label, 'Bunker Labs') !== FALSE) {
    $about_targets = array_values(array_filter([
      $selected_pages['About IVMF'] ?? NULL,
      $selected_pages['History'] ?? NULL,
      $selected_pages['Impact'] ?? NULL,
      $selected_pages['Team'] ?? NULL,
      $selected_pages['Partners & Funders'] ?? NULL,
      $selected_pages['Bunker Labs: A Legacy of Impact'] ?? NULL,
    ]));
    if ($about_targets) {
      $tree[] = import_component_items($components, 'action-bar', [
        'column_1_heading' => $about_targets[0]['h1'] ?: ($about_targets[0]['page_title'] ?? 'About IVMF'),
        'column_1_description' => import_excerpt($about_targets[0]['main_text'] ?? '', 120),
        'column_1_link_text' => $about_targets[0]['page_title'] ?? 'Open page',
        'column_1_accessible_link_text' => 'Open ' . ($about_targets[0]['page_title'] ?? 'page'),
        'column_1_link_url' => '/canvas-import/' . import_slug($about_targets[0]['h1'] ?: $about_targets[0]['page_title'] ?: 'page-1'),
        'column_2_heading' => $about_targets[1]['h1'] ?: ($about_targets[1]['page_title'] ?? 'History'),
        'column_2_description' => import_excerpt($about_targets[1]['main_text'] ?? '', 120),
        'column_2_link_text' => $about_targets[1]['page_title'] ?? 'Open page',
        'column_2_accessible_link_text' => 'Open ' . ($about_targets[1]['page_title'] ?? 'page'),
        'column_2_link_url' => '/canvas-import/' . import_slug($about_targets[1]['h1'] ?: $about_targets[1]['page_title'] ?: 'page-2'),
        'column_3_heading' => $about_targets[2]['h1'] ?: ($about_targets[2]['page_title'] ?? 'Impact'),
        'column_3_description' => import_excerpt($about_targets[2]['main_text'] ?? '', 120),
        'column_3_link_text' => $about_targets[2]['page_title'] ?? 'Open page',
        'column_3_accessible_link_text' => 'Open ' . ($about_targets[2]['page_title'] ?? 'page'),
        'column_3_link_url' => '/canvas-import/' . import_slug($about_targets[2]['h1'] ?: $about_targets[2]['page_title'] ?: 'page-3'),
      ], 'About Action Bar', '27150000-0000-4000-8000-000000000001');
    }
  }

  if (stripos($label, 'Buy Military-Owned') !== FALSE || in_array($label, ['Apparel and Accessories', 'Beer & Spirits', 'Coffee', 'Books', 'Gifts', 'Tactical, Safety & Fitness Products', 'Services', 'Self Care', 'Hobbies, Sports & Games'], TRUE)) {
    $bmo_targets = array_values(array_filter([
      $selected_pages['Buy Military-Owned Guide'] ?? NULL,
      $selected_pages['Buy Military-Owned Service Submission'] ?? NULL,
      $selected_pages['Buy Military-Owned Product Submission'] ?? NULL,
    ]));
    if ($bmo_targets) {
      $tree[] = import_component_items($components, 'action-bar', [
        'column_1_heading' => $bmo_targets[0]['h1'] ?: ($bmo_targets[0]['page_title'] ?? 'Buy Military-Owned Guide'),
        'column_1_description' => import_excerpt($bmo_targets[0]['main_text'] ?? '', 120),
        'column_1_link_text' => $bmo_targets[0]['page_title'] ?? 'Open page',
        'column_1_accessible_link_text' => 'Open ' . ($bmo_targets[0]['page_title'] ?? 'page'),
        'column_1_link_url' => '/canvas-import/' . import_slug($bmo_targets[0]['h1'] ?: $bmo_targets[0]['page_title'] ?: 'page-1'),
        'column_2_heading' => $bmo_targets[1]['h1'] ?: ($bmo_targets[1]['page_title'] ?? 'Service Submission'),
        'column_2_description' => import_excerpt($bmo_targets[1]['main_text'] ?? '', 120),
        'column_2_link_text' => $bmo_targets[1]['page_title'] ?? 'Open page',
        'column_2_accessible_link_text' => 'Open ' . ($bmo_targets[1]['page_title'] ?? 'page'),
        'column_2_link_url' => '/canvas-import/' . import_slug($bmo_targets[1]['h1'] ?: $bmo_targets[1]['page_title'] ?: 'page-2'),
        'column_3_heading' => $bmo_targets[2]['h1'] ?: ($bmo_targets[2]['page_title'] ?? 'Product Submission'),
        'column_3_description' => import_excerpt($bmo_targets[2]['main_text'] ?? '', 120),
        'column_3_link_text' => $bmo_targets[2]['page_title'] ?? 'Open page',
        'column_3_accessible_link_text' => 'Open ' . ($bmo_targets[2]['page_title'] ?? 'page'),
        'column_3_link_url' => '/canvas-import/' . import_slug($bmo_targets[2]['h1'] ?: $bmo_targets[2]['page_title'] ?: 'page-3'),
      ], 'Buy Military-Owned Action Bar', '28250000-0000-4000-8000-000000000001');
    }
  }

  if (stripos($label, 'Volunteer') !== FALSE) {
    $tree[] = import_component_items($components, 'action-bar', [
      'column_1_heading' => 'Support the mission',
      'column_1_description' => import_excerpt($source_page['main_text'] ?? '', 120),
      'column_1_link_text' => 'Buy Military-Owned Guide',
      'column_1_accessible_link_text' => 'Open the buy military-owned guide',
      'column_1_link_url' => '/canvas-import/' . import_slug(($selected_pages['Buy Military-Owned Guide']['h1'] ?? $selected_pages['Buy Military-Owned Guide']['page_title'] ?? 'buy-military-owned-guide')),
      'column_2_heading' => 'Volunteer',
      'column_2_description' => 'Help connect the community to the right opportunities.',
      'column_2_link_text' => 'Volunteer Form',
      'column_2_accessible_link_text' => 'Open the volunteer form',
      'column_2_link_url' => '/canvas-import/' . import_slug(($selected_pages['Volunteer Form']['h1'] ?? $selected_pages['Volunteer Form']['page_title'] ?? 'volunteer-form')),
      'column_3_heading' => 'Partner with IVMF',
      'column_3_description' => 'Explore other support and alumni pathways.',
      'column_3_link_text' => 'Support IVMF',
      'column_3_accessible_link_text' => 'Open support IVMF',
      'column_3_link_url' => '/canvas-import/' . import_slug(($selected_pages['Support IVMF']['h1'] ?? $selected_pages['Support IVMF']['page_title'] ?? 'support-ivmf')),
    ], 'Volunteer Action Bar', '28260000-0000-4000-8000-000000000001');
  }

  if (stripos($label, 'Project') !== FALSE || stripos($label, 'Review') !== FALSE) {
    $project_targets = array_values(array_filter([
      $selected_pages['Current Projects'] ?? NULL,
      $selected_pages['Past Projects'] ?? NULL,
      $selected_pages['Research Reviews'] ?? NULL,
      $selected_pages['Research Projects'] ?? NULL,
    ]));
    if ($project_targets) {
      $tree[] = import_component_items($components, 'action-bar', [
        'column_1_heading' => $project_targets[0]['h1'] ?: ($project_targets[0]['page_title'] ?? 'Current Projects'),
        'column_1_description' => import_excerpt($project_targets[0]['main_text'] ?? '', 120),
        'column_1_link_text' => $project_targets[0]['page_title'] ?? 'Open page',
        'column_1_accessible_link_text' => 'Open ' . ($project_targets[0]['page_title'] ?? 'page'),
        'column_1_link_url' => '/canvas-import/' . import_slug($project_targets[0]['h1'] ?: $project_targets[0]['page_title'] ?: 'page-1'),
        'column_2_heading' => $project_targets[1]['h1'] ?: ($project_targets[1]['page_title'] ?? 'Past Projects'),
        'column_2_description' => import_excerpt($project_targets[1]['main_text'] ?? '', 120),
        'column_2_link_text' => $project_targets[1]['page_title'] ?? 'Open page',
        'column_2_accessible_link_text' => 'Open ' . ($project_targets[1]['page_title'] ?? 'page'),
        'column_2_link_url' => '/canvas-import/' . import_slug($project_targets[1]['h1'] ?: $project_targets[1]['page_title'] ?: 'page-2'),
        'column_3_heading' => $project_targets[2]['h1'] ?: ($project_targets[2]['page_title'] ?? 'Research Reviews'),
        'column_3_description' => import_excerpt($project_targets[2]['main_text'] ?? '', 120),
        'column_3_link_text' => $project_targets[2]['page_title'] ?? 'Open page',
        'column_3_accessible_link_text' => 'Open ' . ($project_targets[2]['page_title'] ?? 'page'),
        'column_3_link_url' => '/canvas-import/' . import_slug($project_targets[2]['h1'] ?: $project_targets[2]['page_title'] ?: 'page-3'),
      ], 'Project Action Bar', '28000000-0000-4000-8000-000000000001');
    }
  }

  if (stripos($label, 'Dashboard') !== FALSE) {
    $dashboard_targets = array_values(array_filter([
      $selected_pages['V-START Dashboards'] ?? NULL,
      $selected_pages['National Survey of Military-Affiliated Entrepreneurs (NSMAE) Dashboard'] ?? NULL,
      $selected_pages['Data Insights & Tools'] ?? NULL,
    ]));
    if ($dashboard_targets) {
      $tree[] = import_component_items($components, 'promo-banner', [
        'heading' => $dashboard_targets[0]['h1'] ?: ($dashboard_targets[0]['page_title'] ?? 'Dashboards'),
        'content' => import_excerpt($dashboard_targets[0]['main_text'] ?? '', 220),
        'image' => import_first_image_payload($dashboard_targets[0]),
        'image_position' => 'right',
        'theme' => 'dark',
        'link_url' => $dashboard_targets[0]['final_url'] ?: $dashboard_targets[0]['source_url'],
        'link_text' => 'Open dashboard',
        'accessible_link_text' => 'Open ' . ($dashboard_targets[0]['page_title'] ?? 'dashboard'),
        'second_link_url' => '/canvas-import/component-map',
        'second_link_text' => 'Open component map',
        'second_accessible_link_text' => 'Open component map',
      ], 'Dashboard Promo Banner', '28150000-0000-4000-8000-000000000001');
    }
  }

  import_upsert_canvas_page(
    'IVMF Imported: ' . ($source_page['h1'] ?: $source_page['page_title'] ?: $label),
    import_alias_from_url($source_page['final_url'] ?: $source_page['source_url']),
    import_excerpt($source_page['meta_description'] ?: ($source_page['main_text'] ?? ''), 240),
    $tree
  );
}

$people_tree = [];
$people_tree[] = import_component_items($components, 'hero', [
  'eyebrow' => 'Imported People',
  'heading' => 'IVMF People Directory',
  'body' => 'Reusable people profiles reconstructed from the crawl output.',
  'link_text' => 'View sample profiles',
  'link_url' => '/canvas-import/people',
], 'Hero', '31000000-0000-4000-8000-000000000001');

$people_layout_uuid = '31000000-0000-4000-8000-000000000002';
$people_tree[] = import_component_items($components, 'layout-container', [
  'heading' => 'Directory',
  'columns' => 3,
], 'Directory Layout', $people_layout_uuid);

foreach (array_slice($people, 0, 12) as $index => $person) {
  $people_tree[] = import_component_items($components, 'card', [
    'number' => sprintf('%02d', $index + 1),
    'heading' => $person['name'] ?: 'Person',
    'content' => import_excerpt($person['title'] ?: $person['bio_text'] ?: ''),
    'link_text' => 'Open profile',
    'link_url' => '/canvas-import/people/' . import_slug($person['name'] ?: 'person'),
  ], 'People Card', sprintf('32000000-0000-4000-8000-%012d', $index + 1), $people_layout_uuid, 'content');
}

import_upsert_canvas_page('IVMF People Directory', '/canvas-import/people', 'Imported directory-style Canvas page for people profiles.', $people_tree);

foreach (array_slice($people, 0, 12) as $index => $person) {
  $profile_tree = [];
  $profile_tree[] = import_component_items($components, 'hero', [
    'eyebrow' => 'People Profile',
    'heading' => $person['name'] ?: 'Person Profile',
    'body' => $person['title'] ?: 'Imported staff bio',
    'link_text' => 'Back to people directory',
    'link_url' => '/canvas-import/people',
  ], 'Hero', '41000000-0000-4000-8000-000000000001');
  $profile_tree[] = import_component_items($components, 'text-content', [
    'heading' => 'Biography',
    'content' => import_excerpt($person['bio_text'] ?? '', 1200),
  ], 'Bio Text', '41000000-0000-4000-8000-000000000002');

  if (!empty($person['profile_links'])) {
    $layout_uuid = '41000000-0000-4000-8000-000000000010';
    $profile_tree[] = import_component_items($components, 'layout-container', [
      'heading' => 'Contact & Profiles',
      'columns' => 3,
    ], 'Contact Links', $layout_uuid);
    foreach (array_slice($person['profile_links'], 0, 3) as $link_index => $link) {
      $profile_tree[] = import_component_items($components, 'card', [
        'number' => sprintf('%02d', $link_index + 1),
        'heading' => $link['text'] ?: 'Profile link',
        'content' => $link['url'] ?? '',
        'link_text' => $link['text'] ?: 'Open',
        'link_url' => $link['url'] ?? '',
      ], 'Profile Link ' . ($link_index + 1), sprintf('41000000-0000-4000-8000-%012d', $index + 3 + $link_index), $layout_uuid, 'content');
    }
  }

  import_upsert_canvas_page(
    'IVMF People: ' . ($person['name'] ?: 'Profile'),
    '/canvas-import/people/' . import_slug($person['name'] ?: 'profile-' . ($index + 1)),
    import_excerpt($person['title'] ?: '', 200),
    $profile_tree
  );
}

$testimonial_tree = [];
$testimonial_tree[] = import_component_items($components, 'hero', [
  'eyebrow' => 'Imported Testimonials',
  'heading' => 'IVMF Testimonials Repository',
  'body' => 'Deduplicated testimonial content from the crawl output.',
  'link_text' => 'Back to component map',
  'link_url' => '/canvas-import/component-map',
], 'Hero', '51000000-0000-4000-8000-000000000001');

foreach (array_slice($testimonials, 0, 6) as $index => $testimonial) {
  $testimonial_tree[] = import_component_items($components, 'testimonial', [
    'quote' => '<p>' . htmlspecialchars($testimonial['quote'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</p>',
    'cite_name' => import_excerpt($testimonial['citation'] ?? '', 100),
    'program' => 'Imported testimonial',
    'program_year' => "'22",
    'school' => 'Syracuse University',
    'workplace_name' => 'IVMF Sandbox',
    'theme' => ($index % 2 === 0) ? 'dark' : 'light',
    'image' => import_image_payload(['url' => $testimonial['image_url'] ?? '', 'alt' => $testimonial['image_alt'] ?? '']),
    'link_url' => '/canvas-import/component-map',
    'link_text' => 'Open component map',
    'accessible_link_text' => 'Open the canvas component map page',
  ], 'Testimonial', sprintf('52000000-0000-4000-8000-%012d', $index + 1));
}

import_upsert_canvas_page('IVMF Testimonials Repository', '/canvas-import/testimonials', 'Imported testimonial repository page.', $testimonial_tree);

print "Imported Canvas content into sandbox pages using crawl output from {$output_dir}." . PHP_EOL;
