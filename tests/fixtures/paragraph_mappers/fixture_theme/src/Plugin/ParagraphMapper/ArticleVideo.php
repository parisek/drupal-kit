<?php

declare(strict_types=1);

namespace Drupal\fixture_theme\Plugin\ParagraphMapper;

use Drupal\drupal_kit_paragraphs\Attribute\ParagraphMapper;
use Drupal\drupal_kit_paragraphs\Mapper\ParagraphMapperBase;
use Drupal\drupal_kit_paragraphs\Mapping\MappingContext;
use Drupal\drupal_kit_paragraphs\Mapping\ParagraphMapping;

/**
 * Maps the media structure of an article video to `video` and `image`.
 *
 * The Media field gives one array. Its `iframe` key is the video embed. The
 * template wants the embed in `video` and an image as poster. The image field
 * wins; the thumbnail of the media is the fallback.
 */
#[ParagraphMapper(id: 'fixture_theme_article_video', component: 'article-video')]
class ArticleVideo extends ParagraphMapperBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ParagraphMapping $mapping, MappingContext $context): void {
    $paragraph = $context->getParagraph();
    $media = $this->entityHelper->getMediaField($paragraph, 'media');
    $image = $this->entityHelper->getMediaField($paragraph, 'image');

    $mapping->set('video', $media['iframe'] ?? NULL);
    $mapping->set('image', !empty($image) ? $image : ($media['image'] ?? NULL));
  }

}
