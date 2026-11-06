<?php

declare(strict_types=1);

namespace Drupal\drupal_kit_paragraphs\Mapper;

/**
 * The callables a colocated `<component>.drupal.php` file returned.
 *
 * - alter: Closure(ParagraphMapping, MappingContext, EntityHelper): void.
 *   Runs after the generic mapping (or after replace).
 * - replace: Closure(ParagraphInterface, string, EntityHelper):
 *   ParagraphMapping. Replaces the generic mapping completely.
 */
final class ComponentMapperFile {

  /**
   * Constructs a ComponentMapperFile.
   *
   * @param string $path
   *   The real path of the file, for log messages.
   * @param \Closure|null $alter
   *   The alter step.
   * @param \Closure|null $replace
   *   The replace step.
   */
  public function __construct(
    private readonly string $path,
    private readonly ?\Closure $alter,
    private readonly ?\Closure $replace = NULL,
  ) {}

  /**
   * Gets the real path of the file.
   */
  public function getPath(): string {
    return $this->path;
  }

  /**
   * Gets the alter step.
   */
  public function getAlter(): ?\Closure {
    return $this->alter;
  }

  /**
   * Gets the replace step.
   */
  public function getReplace(): ?\Closure {
    return $this->replace;
  }

}
