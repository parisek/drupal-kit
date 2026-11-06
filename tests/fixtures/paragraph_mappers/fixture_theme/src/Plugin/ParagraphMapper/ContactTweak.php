<?php

declare(strict_types=1);

namespace Drupal\fixture_theme\Plugin\ParagraphMapper;

use Drupal\drupal_kit_paragraphs\Attribute\ParagraphMapper;
use Drupal\drupal_kit_paragraphs\Mapper\ParagraphMapperBase;
use Drupal\drupal_kit_paragraphs\Mapping\MappingContext;
use Drupal\drupal_kit_paragraphs\Mapping\ParagraphMapping;

/**
 * Runs late for the contact component, to test the run order.
 */
#[ParagraphMapper(id: 'fixture_theme_contact_late', component: 'contact', priority: 10)]
class ContactTweak extends ParagraphMapperBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ParagraphMapping $mapping, MappingContext $context): void {
    $mapping->set('order', $mapping->get('order', '') . 'late;');
  }

}
