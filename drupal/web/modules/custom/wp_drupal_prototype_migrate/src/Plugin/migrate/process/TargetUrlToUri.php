<?php

declare(strict_types=1);

namespace Drupal\wp_drupal_prototype_migrate\Plugin\migrate\process;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;
use Drupal\wp_drupal_prototype_migrate\Service\LegacyUrlHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Converts mapped target paths to Drupal redirect URIs.
 *
 * @MigrateProcessPlugin(
 *   id = "target_url_to_uri"
 * )
 */
final class TargetUrlToUri extends ProcessPluginBase implements ContainerFactoryPluginInterface {

  public function __construct(array $configuration, $plugin_id, $plugin_definition, private readonly LegacyUrlHelper $legacyUrlHelper) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('wp_drupal_prototype_migrate.legacy_url_helper')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property): string {
    return $this->legacyUrlHelper->targetUrlToUri((string) $value);
  }

}
