<?php

/**
 * @file
 * Base theme file of a component the active theme does not have.
 */

use Drupal\drupal_kit\Services\EntityHelper;
use Drupal\drupal_kit_paragraphs\Mapping\MappingContext;
use Drupal\drupal_kit_paragraphs\Mapping\ParagraphMapping;

return static function (ParagraphMapping $mapping, MappingContext $context, EntityHelper $fields): void {
  $mapping->set('from', 'base-only');
};
