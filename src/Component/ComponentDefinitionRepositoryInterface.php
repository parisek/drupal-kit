<?php

declare(strict_types=1);

namespace Drupal\drupal_kit\Component;

/**
 * Finds component definitions in the Components registry.
 */
interface ComponentDefinitionRepositoryInterface {

  /**
   * Tells if a string is a valid component name.
   *
   * A component name is kebab-case machine name. The check runs before any
   * path is built.
   */
  public function isValidComponentName(string $name): bool;

  /**
   * Resolves a bundle machine name to a component name.
   *
   * @param string $bundle
   *   The bundle machine name, for example `article_video`.
   *
   * @return string|null
   *   The component name, or NULL when the bundle is excluded or the result is
   *   not a valid component name.
   */
  public function resolveComponentName(string $bundle): ?string;

  /**
   * Loads the definition of a component.
   *
   * @param string $component
   *   The component name.
   *
   * @return \Drupal\drupal_kit\Component\ComponentDefinition|null
   *   The definition, or NULL when the name is invalid or no file exists.
   *
   * @throws \Drupal\drupal_kit\Component\InvalidComponentDefinitionException
   *   When the file exists but is malformed.
   */
  public function load(string $component): ?ComponentDefinition;

  /**
   * Loads the definition that belongs to a bundle.
   *
   * @throws \Drupal\drupal_kit\Component\InvalidComponentDefinitionException
   *   When the file exists but is malformed.
   */
  public function loadForBundle(string $bundle): ?ComponentDefinition;

}
