<?php

/**
 * @file
 * A file with both steps.
 */

use Drupal\drupal_kit\Services\EntityHelper;
use Drupal\drupal_kit_paragraphs\Mapping\MappingContext;
use Drupal\drupal_kit_paragraphs\Mapping\ParagraphMapping;
use Drupal\paragraphs\ParagraphInterface;

return [
  'replace' => static function (ParagraphInterface $paragraph, string $view_mode, EntityHelper $fields): ParagraphMapping {
    return (new ParagraphMapping())->set('built', 'by replace');
  },
  'alter' => static function (ParagraphMapping $mapping, MappingContext $context, EntityHelper $fields): void {
    $mapping->set('altered', TRUE);
  },
];
