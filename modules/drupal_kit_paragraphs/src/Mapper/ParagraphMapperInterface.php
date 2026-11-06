<?php

declare(strict_types=1);

namespace Drupal\drupal_kit_paragraphs\Mapper;

use Drupal\drupal_kit_paragraphs\Mapping\MappingContext;
use Drupal\drupal_kit_paragraphs\Mapping\ParagraphMapping;

/**
 * Changes the generic mapping of one component.
 *
 * The generic mapper builds the mapping from the component definition first.
 * A plugin then fixes what the definition cannot express, for example a media
 * structure that the template wants in another shape.
 */
interface ParagraphMapperInterface {

  /**
   * Alters the mapping in place.
   *
   * @param \Drupal\drupal_kit_paragraphs\Mapping\ParagraphMapping $mapping
   *   The mapping built so far.
   * @param \Drupal\drupal_kit_paragraphs\Mapping\MappingContext $context
   *   The paragraph, the view mode and the definition.
   */
  public function alter(ParagraphMapping $mapping, MappingContext $context): void;

}
