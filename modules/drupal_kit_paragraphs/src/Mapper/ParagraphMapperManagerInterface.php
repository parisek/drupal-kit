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
   * Plugins of enabled modules always qualify. A theme plugin qualifies only
   * when its theme is in the chain that supplied the component: the active
   * theme chain when it holds the component, otherwise the default theme
   * chain, which is the Components registry fallback.
   *
   * @param string $component
   *   The component name.
   * @param string|null $provider
   *   The module or theme that supplied the component definition, from
   *   ComponentDefinition::getProvider().
   *
   * @return \Drupal\drupal_kit_paragraphs\Mapper\ParagraphMapperInterface[]
   *   The plugin instances, keyed by plugin ID.
   */
  public function getMappersForComponent(string $component, ?string $provider = NULL): array;

}
