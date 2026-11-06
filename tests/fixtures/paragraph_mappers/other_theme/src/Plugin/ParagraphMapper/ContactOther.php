<?php

declare(strict_types=1);

namespace Drupal\other_theme\Plugin\ParagraphMapper;

use Drupal\drupal_kit_paragraphs\Attribute\ParagraphMapper;
use Drupal\drupal_kit_paragraphs\Mapper\ParagraphMapperBase;
use Drupal\drupal_kit_paragraphs\Mapping\MappingContext;
use Drupal\drupal_kit_paragraphs\Mapping\ParagraphMapping;

/**
 * Plugin of an installed theme that is not in the active chain.
 */
#[ParagraphMapper(id: 'other_theme_contact', component: 'contact')]
class ContactOther extends ParagraphMapperBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ParagraphMapping $mapping, MappingContext $context): void {
    $mapping->set('order', 'WRONG;');
  }

}
