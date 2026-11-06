<?php

declare(strict_types=1);

namespace Drupal\drupal_kit_paragraphs\Mapping;

use Drupal\drupal_kit\Component\ComponentDefinition;
use Drupal\paragraphs\ParagraphInterface;

/**
 * Read-only input of one mapping run.
 *
 * Mapper plugins and alter hooks get the context next to the mapping. They
 * read the paragraph, the view mode and the definition here. They write only
 * to the mapping.
 */
final class MappingContext {

  /**
   * Constructs a MappingContext.
   */
  public function __construct(
    private readonly ParagraphInterface $paragraph,
    private readonly string $viewMode,
    private readonly ComponentDefinition $definition,
  ) {}

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
