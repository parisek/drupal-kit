<?php

declare(strict_types=1);

namespace Drupal\drupal_kit_paragraphs\Mapper;

use Drupal\drupal_kit\Component\ComponentDefinition;

/**
 * Loads the colocated PHP file of a component.
 */
interface ComponentMapperFileLoaderInterface {

  /**
   * Loads `<component>.php` from the component directory.
   *
   * @param \Drupal\drupal_kit\Component\ComponentDefinition $definition
   *   The definition. Its directory is the one the Components registry
   *   resolved, so Twig precedence decides which file wins.
   *
   * @return \Drupal\drupal_kit_paragraphs\Mapper\ComponentMapperFile|null
   *   The callables, or NULL when the component has no such file.
   *
   * @throws \Drupal\drupal_kit\Component\InvalidComponentDefinitionException
   *   When the file is unsafe or breaks the contract. Exceptions that the
   *   file's own code throws pass through.
   */
  public function load(ComponentDefinition $definition): ?ComponentMapperFile;

}
