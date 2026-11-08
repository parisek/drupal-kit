<?php

declare(strict_types=1);

namespace Drupal\drupal_kit_paragraphs\Mapper;

use Drupal\drupal_kit_paragraphs\Mapping\ParagraphMapping;
use Drupal\paragraphs\ParagraphInterface;

/**
 * Maps a paragraph to the content of its Twig component.
 *
 * @experimental
 *   The API may change before the module has a stable release.
 */
interface ParagraphComponentMapperInterface {

  /**
   * Maps a paragraph.
   *
   * A missing or malformed component definition gives the alert component
   * ("implementation is missing") and a log entry. The result always carries
   * the `theme` cache context. Other exceptions propagate.
   *
   * @param \Drupal\paragraphs\ParagraphInterface $paragraph
   *   The paragraph.
   * @param string $viewMode
   *   The view mode the paragraph is rendered in.
   */
  public function map(ParagraphInterface $paragraph, string $viewMode): ParagraphMapping;

}
