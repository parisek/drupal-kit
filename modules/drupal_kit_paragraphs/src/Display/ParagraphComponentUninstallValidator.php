<?php

declare(strict_types=1);

namespace Drupal\drupal_kit_paragraphs\Display;

use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleUninstallValidatorInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Refuses to uninstall the module while a paragraph display still uses it.
 *
 * A display that shows `paragraph_component` keeps a reference to a plugin
 * that no module provides after the uninstall, and its paragraphs render
 * nothing. The reason names each display and the command that switches it
 * back.
 */
class ParagraphComponentUninstallValidator implements ModuleUninstallValidatorInterface {

  use StringTranslationTrait;

  /**
   * Constructs a ParagraphComponentUninstallValidator.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * {@inheritdoc}
   */
  public function validate($module): array {
    if ($module !== 'drupal_kit_paragraphs') {
      return [];
    }

    $commands = [];
    $displays = $this->entityTypeManager->getStorage('entity_view_display')->loadByProperties(['targetEntityType' => 'paragraph']);
    foreach ($displays as $display) {
      if ($display instanceof EntityViewDisplay && $display->getComponent(ParagraphDisplaySwitcher::COMPONENT_FIELD) !== NULL) {
        $mode = $display->getMode();
        $commands[] = sprintf('drush drupal-kit:paragraph-switch %s legacy --view-mode=%s', $display->getTargetBundle(), $mode);
      }
    }
    if ($commands === []) {
      return [];
    }

    return [
      $this->t('Paragraph displays still use the paragraph_component field. Switch each back first: @commands', [
        '@commands' => implode('; ', $commands),
      ]),
    ];
  }

}
