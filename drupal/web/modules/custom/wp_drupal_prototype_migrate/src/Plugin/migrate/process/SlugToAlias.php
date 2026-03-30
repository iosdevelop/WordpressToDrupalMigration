<?php

declare(strict_types=1);

namespace Drupal\wp_drupal_prototype_migrate\Plugin\migrate\process;

use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;

/**
 * Generates deterministic aliases from source slugs.
 *
 * @MigrateProcessPlugin(
 *   id = "slug_to_alias"
 * )
 */
final class SlugToAlias extends ProcessPluginBase {

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property): string {
    $slug = trim((string) $value);
    if ($slug === '') {
      return '/prototype/untitled';
    }

    return '/prototype/' . $slug;
  }

}
