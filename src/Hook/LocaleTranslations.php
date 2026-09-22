<?php

declare(strict_types=1);

namespace Drupal\drupal_kit\Hook;

use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Points locale at the module's real path instead of the declared one.
 */
class LocaleTranslations {

  public function __construct(
    protected ExtensionPathResolver $extensionPathResolver,
  ) {}

  /**
   * Implements hook_locale_translation_projects_alter().
   *
   * The info file has to name a path for the module's own .po files, and a
   * path in an info file cannot be computed. The declared one assumes the
   * usual modules/contrib location; a consumer whose installer-paths put the
   * module anywhere else would get a project reported as checked and no
   * translation imported, with nothing said about why.
   *
   * The extension path resolver knows where the module actually is, so this
   * replaces the assumption with the fact.
   *
   * The resolver, not `extension.list.module`. The procedural version read
   * the module extension list, which core marks \@internal — a dependency
   * nothing had to name while the lookup went through \Drupal::service().
   * Declaring the argument made the type visible, and PHPStan reported it.
   * The resolver is the public equivalent and is already what this module's
   * other services take.
   */
  #[Hook('locale_translation_projects_alter')]
  public function localeTranslationProjectsAlter(array &$projects): void {
    if (!isset($projects['drupal_kit'])) {
      return;
    }

    $path = $this->extensionPathResolver->getPath('module', 'drupal_kit');
    $projects['drupal_kit']['info']['interface translation server pattern'] = $path . '/translations/%language.po';
  }

}
