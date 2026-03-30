<?php

declare(strict_types=1);

namespace Drupal\wp_drupal_prototype_migrate\Service;

/**
 * Normalizes legacy URLs for migration process plugins.
 */
final class LegacyUrlHelper {

  /**
   * Converts a full legacy URL to a Drupal redirect source path.
   */
  public function legacyUrlToPath(string $legacy_url): string {
    $legacy_url = trim($legacy_url);

    if ($legacy_url === '') {
      return '/';
    }

    if (str_starts_with($legacy_url, '/')) {
      return $this->normalizePath($legacy_url);
    }

    $path = parse_url($legacy_url, PHP_URL_PATH) ?: '/';
    return $this->normalizePath($path);
  }

  /**
   * Converts a mapped URL/path to an internal redirect URI.
   */
  public function targetUrlToUri(string $target_url): string {
    $target_url = trim($target_url);

    if ($target_url === '') {
      return 'internal:/';
    }

    if (str_starts_with($target_url, 'internal:')) {
      return $target_url;
    }

    if (str_starts_with($target_url, 'http://') || str_starts_with($target_url, 'https://')) {
      $path = parse_url($target_url, PHP_URL_PATH) ?: '/';
      $query = parse_url($target_url, PHP_URL_QUERY);
      $fragment = parse_url($target_url, PHP_URL_FRAGMENT);

      $uri = 'internal:' . $this->normalizePath($path);
      if ($query) {
        $uri .= '?' . $query;
      }
      if ($fragment) {
        $uri .= '#' . $fragment;
      }
      return $uri;
    }

    return 'internal:' . $this->normalizePath($target_url);
  }

  /**
   * Applies stable path normalization.
   */
  private function normalizePath(string $path): string {
    $normalized = '/' . ltrim($path, '/');
    $normalized = preg_replace('#/+#', '/', $normalized) ?: '/';

    if ($normalized !== '/') {
      $normalized = rtrim($normalized, '/');
    }

    return $normalized;
  }

}
