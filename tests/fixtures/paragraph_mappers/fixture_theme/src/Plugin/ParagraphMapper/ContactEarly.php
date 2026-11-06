<?php

declare(strict_types=1);

namespace Drupal\fixture_theme\Plugin\ParagraphMapper;

use Drupal\drupal_kit_paragraphs\Attribute\ParagraphMapper;
use Drupal\drupal_kit_paragraphs\Mapper\ParagraphMapperBase;
use Drupal\drupal_kit_paragraphs\Mapping\MappingContext;
use Drupal\drupal_kit_paragraphs\Mapping\ParagraphMapping;

/**
 * Runs first for the contact component, to test the run order.
 */
#[ParagraphMapper(id: 'fixture_theme_contact_early', component: 'contact', priority: -5)]
class ContactEarly extends ParagraphMapperBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ParagraphMapping $mapping, MappingContext $context): void {
    $mapping->set('order', $mapping->get('order', '') . 'early;');
  }

}
