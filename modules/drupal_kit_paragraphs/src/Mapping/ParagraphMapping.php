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
 *
 * @experimental
 *   The API may change before the module has a stable release.
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
   * The steps that wrote each top-level key, in order.
   *
   * @var array<string, string[]>
   */
  private array $origins = [];

  /**
   * The files that took part: `definition` and `file`, as paths or NULL.
   *
   * @var array<string, string|null>
   */
  private array $sources = [];

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
   * Declares that the content is a list of entities of one type.
   *
   * A query component calls this. The mapping then gets the list cache tag, so
   * a new, changed or removed entity rebuilds the page, and the contexts that
   * decide which entities the user may see. With no bundle the tag is the broad
   * `<type>_list`. With bundles it is `<type>_list:<bundle>` for each.
   *
   * @param string $entityType
   *   The entity type id, for example `node`.
   * @param string ...$bundles
   *   Optional bundles to narrow the tag.
   */
  public function addListDependency(string $entityType, string ...$bundles): static {
    $tags = $bundles === []
      ? [$entityType . '_list']
      : array_map(static fn (string $bundle): string => $entityType . '_list:' . $bundle, $bundles);
    $contexts = $entityType === 'node'
      ? ['user.permissions', 'user.node_grants:view']
      : ['user.permissions'];
    return $this->addCacheableDependency((new CacheableMetadata())->setCacheTags($tags)->setCacheContexts($contexts));
  }

  /**
   * Tells if the cacheability has a list tag or cannot be cached.
   *
   * The mapper uses this to warn about a query component that declared neither.
   */
  public function hasListDependency(): bool {
    if ($this->cacheability->getCacheMaxAge() === 0) {
      return TRUE;
    }
    foreach ($this->cacheability->getCacheTags() as $tag) {
      if (preg_match('/_list(:|$)/', $tag) === 1) {
        return TRUE;
      }
    }
    return FALSE;
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
   * Records which step wrote a top-level key.
   *
   * The mapper calls this after each step, for the diagnostic command. A key
   * written by several steps lists them all, the last one is the winner.
   */
  public function recordOrigin(string $key, string $step): static {
    $this->origins[$key][] = $step;
    return $this;
  }

  /**
   * Gets the steps that wrote each top-level key.
   *
   * @return array<string, string[]>
   *   The step names per key, in the order they ran.
   */
  public function getOrigins(): array {
    return $this->origins;
  }

  /**
   * Records a file that took part, for the diagnostic command.
   */
  public function setSource(string $name, ?string $path): static {
    $this->sources[$name] = $path;
    return $this;
  }

  /**
   * Gets the files that took part.
   *
   * @return array<string, string|null>
   *   The paths, keyed by `definition` and `file`.
   */
  public function getSources(): array {
    return $this->sources;
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
