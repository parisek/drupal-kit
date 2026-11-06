<?php

declare(strict_types=1);

namespace Drupal\drupal_kit_paragraphs\Mapper;

use Drupal\Component\Plugin\PluginManagerInterface;

/**
 * Plugin manager of paragraph mapper plugins.
 */
interface ParagraphMapperManagerInterface extends PluginManagerInterface {

  /**
   * Gets the plugins of a component in run order.
   *
   * Only plugins of enabled modules, of the active theme and of its base
   * themes are returned.
   *
   * @param string $component
   *   The component name.
   *
   * @return \Drupal\drupal_kit_paragraphs\Mapper\ParagraphMapperInterface[]
   *   The plugin instances, keyed by plugin ID.
   */
  public function getMappersForComponent(string $component): array;

}
