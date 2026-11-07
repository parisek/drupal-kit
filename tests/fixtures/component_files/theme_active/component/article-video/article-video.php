<?php

/**
 * @file
 * Maps the media structure of an article video for the article-video template.
 *
 * The Media field gives one array. Its `iframe` key is the embed, which the
 * template wants in `video`. The image field is the poster; the thumbnail of
 * the media is the fallback.
 */

use Drupal\drupal_kit\Services\EntityHelper;
use Drupal\drupal_kit_paragraphs\Mapping\MappingContext;
use Drupal\drupal_kit_paragraphs\Mapping\ParagraphMapping;

return static function (ParagraphMapping $mapping, MappingContext $context, EntityHelper $fields): void {
  $paragraph = $context->getParagraph();
  $image = $fields->getMediaField($paragraph, 'image');
  $media = $fields->getMediaField($paragraph, 'media');

  $mapping->set('video', $media['iframe'] ?? NULL);
  $mapping->set('image', !empty($image) ? $image : ($media['image'] ?? NULL));
};
