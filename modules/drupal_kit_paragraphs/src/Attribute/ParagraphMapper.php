<?php

declare(strict_types=1);

namespace Drupal\drupal_kit_paragraphs\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;

/**
 * Declares a paragraph mapper plugin.
 *
 * A mapper plugin changes the generic mapping of one component. It lives in
 * `src/Plugin/ParagraphMapper` of a module, or of the active theme or one of
 * its base themes.
 *
 * @see \Drupal\drupal_kit_paragraphs\Mapper\ParagraphMapperManager
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class ParagraphMapper extends Plugin {

  /**
   * Constructs a ParagraphMapper attribute.
   *
   * @param string $id
   *   The plugin ID.
   * @param string $component
   *   The component name the plugin applies to, for example `article-video`.
   * @param int $priority
   *   The run order. A lower number runs first, so the higher number wins a
   *   conflict. Plugins with the same priority run in plugin ID order.
   * @param class-string|null $deriver
   *   (optional) The deriver class.
   */
  public function __construct(
    public readonly string $id,
    public readonly string $component,
    public readonly int $priority = 0,
    public readonly ?string $deriver = NULL,
  ) {}

}
