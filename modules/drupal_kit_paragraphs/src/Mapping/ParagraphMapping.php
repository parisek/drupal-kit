<?php

declare(strict_types=1);

namespace Drupal\drupal_kit_paragraphs\Mapping;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableMetadata;

/**
 * Mutable result of mapping one paragraph.
 *
 * The mapping holds the `content` array of the component, the component and
 * template name, and the cacheability. Component files and alter hooks change
 * it in place.
 *
 * A mapping with no component is empty: the mapper found no usable definition
 * or failed. An empty mapping renders nothing.
 */
final class ParagraphMapping {

  /**
   * The content array of the component.
   *
   * @var array<string, mixed>
   */
  private array $data = [];

  /**
   * The accumulated cacheability.
   */
  private CacheableMetadata $cacheability;

  /**
   * Notes about the definition, for example a field the entity lacks.
   *
   * @var string[]
   */
  private array $diagnostics = [];

  /**
   * Constructs a ParagraphMapping.
   *
   * @param string|null $component
   *   The component name, or NULL for an empty mapping.
   * @param string|null $template
   *   The template name. It defaults to the component name.
   */
  public function __construct(
    private ?string $component = NULL,
    private ?string $template = NULL,
  ) {
    $this->cacheability = new CacheableMetadata();
  }

  /**
   * Tells if the mapping has no component, so nothing can render.
   */
  public function isEmpty(): bool {
    return $this->component === NULL;
  }

  /**
   * Gets the component name.
   */
  public function getComponent(): ?string {
    return $this->component;
  }

  /**
   * Sets the component name.
   *
   * The mapper calls this once it has a usable definition.
   */
  public function setComponent(string $component): static {
    $this->component = $component;
    return $this;
  }

  /**
   * Gets the template name.
   */
  public function getTemplate(): ?string {
    return $this->template ?? $this->component;
  }

  /**
   * Sets the template name.
   *
   * A component file or an alter hook calls this to render another template
   * than the component.
   */
  public function setTemplate(string $template): static {
    $this->template = $template;
    return $this;
  }

  /**
   * Gets one top-level value.
   */
  public function get(string $key, mixed $default = NULL): mixed {
    return $this->data[$key] ?? $default;
  }

  /**
   * Tells if a top-level key exists, even with a NULL value.
   */
  public function has(string $key): bool {
    return array_key_exists($key, $this->data);
  }

  /**
   * Sets one top-level value.
   */
  public function set(string $key, mixed $value): static {
    $this->data[$key] = $value;
    return $this;
  }

  /**
   * Removes one top-level value.
   */
  public function remove(string $key): static {
    unset($this->data[$key]);
    return $this;
  }

  /**
   * Gets the whole content array.
   *
   * @return array<string, mixed>
   *   The content array.
   */
  public function getData(): array {
    return $this->data;
  }

  /**
   * Replaces the whole content array.
   *
   * @param array<string, mixed> $data
   *   The new content array.
   */
  public function replaceData(array $data): static {
    $this->data = $data;
    return $this;
  }

  /**
   * Adds a cacheable dependency, for example an entity the mapper loaded.
   */
  public function addCacheableDependency(CacheableDependencyInterface|CacheableMetadata $dependency): static {
    $this->cacheability = $this->cacheability->merge(
      $dependency instanceof CacheableMetadata ? $dependency : CacheableMetadata::createFromObject($dependency)
    );
    return $this;
  }

  /**
   * Gets the accumulated cacheability.
   */
  public function getCacheableMetadata(): CacheableMetadata {
    return $this->cacheability;
  }

  /**
   * Adds a diagnostic note.
   */
  public function addDiagnostic(string $message): static {
    $this->diagnostics[] = $message;
    return $this;
  }

  /**
   * Gets the diagnostic notes.
   *
   * @return string[]
   *   The notes.
   */
  public function getDiagnostics(): array {
    return $this->diagnostics;
  }

  /**
   * Builds the `custom_component` render array.
   *
   * @return array<string, mixed>
   *   The render array, or an array with cacheability only when the mapping
   *   is empty.
   */
  public function toRenderArray(): array {
    $build = [];
    if (!$this->isEmpty()) {
      $build = [
        '#theme' => 'custom_component',
        '#content' => $this->data,
        '#template' => $this->getTemplate(),
      ];
    }
    $this->cacheability->applyTo($build);
    return $build;
  }

}
