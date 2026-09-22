<?php

declare(strict_types=1);

namespace Drupal\drupal_kit\Hook;

use Drupal\Core\Hook\Attribute\Hook;

/**
 * Declares the two base theme hooks every component template rides on.
 */
class ThemeHooks {

  /**
   * Implements hook_theme().
   */
  #[Hook('theme')]
  public function theme(): array {
    return [
      'custom_component' => [
        'base hook' => 'custom_component',
        'variables' => [
          'content' => [],
          'template' => '',
        ],
      ],
      'custom_page' => [
        'base hook' => 'custom_page',
        'variables' => [
          'content' => [],
          'template' => '',
        ],
      ],
    ];
  }

}
