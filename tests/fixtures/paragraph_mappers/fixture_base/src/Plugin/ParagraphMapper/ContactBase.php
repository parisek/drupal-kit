<?php

declare(strict_types=1);

namespace Drupal\fixture_base\Plugin\ParagraphMapper;

use Drupal\drupal_kit_paragraphs\Attribute\ParagraphMapper;
use Drupal\drupal_kit_paragraphs\Mapper\ParagraphMapperBase;
use Drupal\drupal_kit_paragraphs\Mapping\MappingContext;
use Drupal\drupal_kit_paragraphs\Mapping\ParagraphMapping;

/**
 * Plugin of a base theme. Its priority ties with the sub theme plugin.
 */
#[ParagraphMapper(id: 'fixture_base_contact', component: 'contact', priority: 0)]
class ContactBase extends ParagraphMapperBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ParagraphMapping $mapping, MappingContext $context): void {
    $mapping->set('order', $mapping->get('order', '') . 'base;');
  }

}
