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
 * Runs after the generic rules and after the colocated component file. Modules
 * run first. The active theme and its base themes run next. A hook may call
 * $mapping->setTemplate().
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

/**
 * @addtogroup component_files
 * @{
 */

/**
 * The colocated component file `<component>.drupal.php`.
 *
 * Component PHP lives in the component folder, next to `<component>.twig` and
 * `<component>.yaml`. It is not a PSR-4 class: it has no namespace, no class
 * and no attribute. The Components registry picks the folder, so Twig
 * precedence decides the file: active theme, then base themes, then modules.
 *
 * The file must start with `return` and print nothing. It returns one of:
 * - A Closure(ParagraphMapping $mapping, MappingContext $context,
 *   EntityHelper $fields): void. This is the `alter` step. It runs after the
 *   generic mapping from the YAML definition.
 * - An array with `alter` and/or `replace`. `replace` is a
 *   Closure(ParagraphInterface $paragraph, string $view_mode,
 *   EntityHelper $fields): ParagraphMapping that builds the whole mapping and
 *   skips the generic rules and the framework fields.
 *
 * Order: generic mapping (or `replace`), the file's `alter`, then
 * hook_drupal_kit_paragraph_mapping_alter() and its COMPONENT variant.
 *
 * MappingContext::service('id') gives any other service.
 *
 * A component with `role: query` fields and no such file renders the alert
 * component, because nothing would fill those fields.
 *
 * Example, `article-video.drupal.php`:
 * @code
 * <?php
 *
 * use Drupal\drupal_kit\Services\EntityHelper;
 * use Drupal\drupal_kit_paragraphs\Mapping\MappingContext;
 * use Drupal\drupal_kit_paragraphs\Mapping\ParagraphMapping;
 *
 * return static function (ParagraphMapping $mapping, MappingContext $context, EntityHelper $fields): void {
 *   $paragraph = $context->getParagraph();
 *   $image = $fields->getMediaField($paragraph, 'image');
 *   $media = $fields->getMediaField($paragraph, 'media');
 *   $mapping->set('video', $media['iframe'] ?? NULL);
 *   $mapping->set('image', !empty($image) ? $image : ($media['image'] ?? NULL));
 * };
 * @endcode
 *
 * Security: a web server may run a `.php` file under a theme when someone
 * requests its URL. The file prints nothing, so a direct request shows an
 * empty page, and the loader refuses a file that prints. Deny the request at
 * the web server all the same.
 *
 * Apache (.htaccess or the vhost):
 * @code
 * <FilesMatch "\.drupal\.php$">
 *   Require all denied
 * </FilesMatch>
 * @endcode
 *
 * nginx (before the generic PHP location):
 * @code
 * location ~ \.drupal\.php$ {
 *   deny all;
 * }
 * @endcode
 *
 * @} End of "addtogroup component_files".
 */
