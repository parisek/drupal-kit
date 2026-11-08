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
 * The colocated component file `<component>.php`.
 *
 * Component PHP lives in the component folder, next to `<component>.twig` and
 * `<component>.yaml`. It is not a PSR-4 class: it has no namespace, no class
 * and no attribute. The Components registry picks the folder, so Twig
 * precedence decides the file: active theme, then base themes, then modules.
 *
 * The file has no top-level code. It returns one of:
 * - A Closure(ParagraphMapping $mapping, MappingContext $context,
 *   EntityHelper $fields): void. This is the `alter` step. It runs after the
 *   generic mapping from the YAML definition.
 * - An array with `alter` and/or `replace`. `replace` is a
 *   Closure(ParagraphInterface $paragraph, string $view_mode,
 *   EntityHelper $fields, MappingContext $context): ParagraphMapping that
 *   builds the data instead of the generic rules. The framework fields are set
 *   first and its data merges over them.
 *
 * The closures have no class scope and no $this.
 *
 * The source rules keep the file declarative. The first token must be the open
 * tag. Before the first `return` only whitespace, comments,
 * `declare(strict_types=1);` as the first statement and `use` statements may
 * appear. Named functions, classes, interfaces, traits, enums and namespaces
 * are refused anywhere. The loader does not read the source on a request. CI
 * checks it: run `drush drupal-kit:lint-components web/themes/custom`. A
 * redeclared function is a fatal error that PHP cannot catch, and top-level
 * code runs when the file is included. The loader checks the name, the real
 * path and the return value, and the file must print nothing, at any output
 * buffer level. Only a ParseError is recoverable. `exit` or `die` in the file
 * ends the request. A sub theme that copies only the `.twig` and `.yaml` loses
 * the base theme's `.php`. A Fiber that suspends during a mapping is not
 * supported.
 *
 * To see how a paragraph is mapped, run `drush drupal-kit:paragraph-map ID`. It
 * shows the step that wrote each content key. To move a bundle between its
 * legacy display and the component, run
 * `drush drupal-kit:paragraph-switch BUNDLE component|legacy`.
 *
 * Order: generic mapping (or `replace`), the file's `alter`, then
 * hook_drupal_kit_paragraph_mapping_alter() and its COMPONENT variant.
 *
 * MappingContext::service('id') gives any other service.
 *
 * A component with `role: query` fields and no such file renders the alert
 * component, because nothing would fill those fields.
 *
 * Example, `article-video.php`:
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
 * Security: the loader cannot stop a web server from running a component file
 * when someone requests its URL. Because of the checks above, such a request
 * runs only the `return` expression, which builds closures and prints nothing.
 * Deny it at the web server all the same. Only the fixed `<component>.php`
 * name is ever loaded, never the `.twig` or `.yaml`.
 *
 * Drupal core's web/.htaccess already denies direct `.php` requests under
 * folders (RewriteRule "^(.+/.*|autoload)\.php($|/)" - [F]). The Apache rule
 * below is only for servers without it. The real exposure is an nginx
 * configuration that sends every `.php` file to PHP-FPM. No test covers the
 * server rule.
 *
 * Apache, scoped to the folder that holds the components:
 * @code
 * <FilesMatch "\.php$">
 *   Require all denied
 * </FilesMatch>
 * @endcode
 *
 * nginx, before the PHP location (covers themes and modules):
 * @code
 * location ~* ^/(themes|modules)/.+\.php$ {
 *   deny all;
 * }
 * @endcode
 *
 * @} End of "addtogroup component_files".
 */
