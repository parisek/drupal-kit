<?php

declare(strict_types=1);

namespace Drupal\drupal_kit_paragraphs\Mapper;

use Drupal\drupal_kit_paragraphs\Mapping\ParagraphMapping;
use Drupal\paragraphs\ParagraphInterface;

/**
 * Maps a paragraph to the content of its Twig component.
 */
interface ParagraphComponentMapperInterface {

  /**
   * Maps a paragraph.
   *
   * The result is empty (see ParagraphMapping::isEmpty()) when the bundle has
   * no usable component definition or when the mapping fails. The mapper logs
   * the reason. It never returns partial output.
   *
   * @param \Drupal\paragraphs\ParagraphInterface $paragraph
   *   The paragraph.
   * @param string $viewMode
   *   The view mode the paragraph is rendered in.
   */
  public function map(ParagraphInterface $paragraph, string $viewMode): ParagraphMapping;

}
