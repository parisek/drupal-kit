<?php

declare(strict_types=1);

namespace Drupal\drupal_kit\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\drupal_kit\Services\ViteManifest;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Points a library's JS asset at the file Vite actually built.
 */
class Libraries {

  public function __construct(
    #[Autowire(service: 'drupal_kit.vite_manifest')]
    protected ViteManifest $viteManifest,
  ) {}

  /**
   * Implements hook_library_info_alter().
   *
   * Rewrites a library's JS asset to the content-hashed filename Vite
   * recorded in `.vite/manifest.json`, for libraries that opt in with
   * `vite_entry`. A library without the property, or a build with no
   * manifest, is left exactly as declared.
   *
   * @see \Drupal\drupal_kit\Services\ViteManifest
   */
  #[Hook('library_info_alter')]
  public function libraryInfoAlter(array &$libraries, string $extension): void {
    $this->viteManifest->alterLibraries($libraries, $extension);
  }

}
