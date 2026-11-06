<?php

/**
 * @file
 * Hooks of the Drupal Kit Paragraphs module.
 */

use Drupal\drupal_kit_paragraphs\Mapping\MappingContext;
use Drupal\drupal_kit_paragraphs\Mapping\ParagraphMapping;

/**
 * @addtogroup hooks
 * @{
 */

/**
 * Alters the mapping of every paragraph component.
 *
 * Runs after the generic rules and after the mapper plugins. Modules run
 * first. The active theme and its base themes run next.
 *
 * Implement it as an OOP hook with
 * #[Hook('drupal_kit_paragraph_mapping_alter')] on a method of a class in
 * src/Hook, in a module or in a theme. A procedural function works too.
 *
 * @param \Drupal\drupal_kit_paragraphs\Mapping\ParagraphMapping $mapping
 *   The mapping. Change it in place.
 * @param \Drupal\drupal_kit_paragraphs\Mapping\MappingContext $context
 *   The paragraph, the view mode and the component definition.
 *
 * @see hook_drupal_kit_paragraph_mapping_COMPONENT_alter()
 */
function hook_drupal_kit_paragraph_mapping_alter(ParagraphMapping $mapping, MappingContext $context): void {
  $mapping->set('analytics_id', $context->getParagraph()->id());
}

/**
 * Alters the mapping of one paragraph component.
 *
 * COMPONENT is the component name with underscores: the `article-video`
 * component uses hook_drupal_kit_paragraph_mapping_article_video_alter().
 * It runs after hook_drupal_kit_paragraph_mapping_alter(). The OOP form is
 * #[Hook('drupal_kit_paragraph_mapping_article_video_alter')].
 *
 * @param \Drupal\drupal_kit_paragraphs\Mapping\ParagraphMapping $mapping
 *   The mapping. Change it in place.
 * @param \Drupal\drupal_kit_paragraphs\Mapping\MappingContext $context
 *   The paragraph, the view mode and the component definition.
 *
 * @see hook_drupal_kit_paragraph_mapping_alter()
 */
function hook_drupal_kit_paragraph_mapping_COMPONENT_alter(ParagraphMapping $mapping, MappingContext $context): void {
  $mapping->remove('legacy_key');
}

/**
 * @} End of "addtogroup hooks".
 */
