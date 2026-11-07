<?php

/**
 * @file
 * Active theme file. It wins over the base theme file of the same component.
 */

use Drupal\drupal_kit\Services\EntityHelper;
use Drupal\drupal_kit_paragraphs\Mapping\MappingContext;
use Drupal\drupal_kit_paragraphs\Mapping\ParagraphMapping;

return static function (ParagraphMapping $mapping, MappingContext $context, EntityHelper $fields): void {
  $mapping->set('from', 'active');
};
