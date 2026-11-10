<?php

declare(strict_types=1);

namespace Drupal\drupal_kit_paragraphs\Mapper;

/**
 * The alter closure that a colocated `<component>.php` file returned.
 *
 * Closure(ParagraphMapping, MappingContext): void. It runs after the generic
 * mapping. The mapper also passes the EntityHelper as a third argument for
 * older files.
 */
final class ComponentMapperFile {

  /**
   * Constructs a ComponentMapperFile.
   *
   * @param string $path
   *   The real path of the file, for log messages.
   * @param \Closure $alter
   *   The alter step.
   */
  public function __construct(
    private readonly string $path,
    private readonly \Closure $alter,
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
  public function getAlter(): \Closure {
    return $this->alter;
  }

}
