<?php

declare(strict_types=1);

use Drupal\canvas\Entity\Page;
use Drupal\canvas\Entity\Component;

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

function import_load_components(array $ids): array {
  $components = [];
  foreach ($ids as $short_id) {
    $component_id = "sdc.prototype_showcase.$short_id";
    $component = Component::load($component_id);
    if (!$component) {
      throw new RuntimeException("Canvas component unavailable: {$component_id}");
    }
    $components[$short_id] = $component;
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
