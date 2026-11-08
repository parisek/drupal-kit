<?php

declare(strict_types=1);

namespace Drupal\drupal_kit_paragraphs\Mapping;

use Drupal\drupal_kit\Component\ComponentDefinition;
use Drupal\paragraphs\ParagraphInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Read-only input of one mapping run.
 *
 * Component files and alter hooks get the context next to the mapping. They
 * read the paragraph, the view mode and the definition here. They write only
 * to the mapping.
 *
 * @experimental
 *   The API may change before the module has a stable release.
 */
final class MappingContext {

  /**
   * Constructs a MappingContext.
   */
  public function __construct(
    private readonly ParagraphInterface $paragraph,
    private readonly string $viewMode,
    private readonly ComponentDefinition $definition,
    private readonly ?ContainerInterface $container = NULL,
  ) {}

  /**
   * Gets any service, for a colocated component file that needs one.
   *
   * @param string $id
   *   The service id.
   *
   * @return object
   *   The service.
   *
   * @throws \LogicException
   *   When the context was built without a container.
   */
  public function service(string $id): object {
    if ($this->container === NULL) {
      throw new \LogicException('This mapping context has no service container.');
    }
    return $this->container->get($id);
  }

  /**
   * Gets the paragraph that is mapped.
   */
  public function getParagraph(): ParagraphInterface {
    return $this->paragraph;
  }

  /**
   * Gets the view mode.
   */
  public function getViewMode(): string {
    return $this->viewMode;
  }

  /**
   * Gets the parsed component definition.
   */
  public function getDefinition(): ComponentDefinition {
    return $this->definition;
  }

  /**
   * Gets the component name.
   */
  public function getComponent(): string {
    return $this->definition->getName();
  }

}
