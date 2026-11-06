<?php

declare(strict_types=1);

namespace Drupal\drupal_kit\Component;

/**
 * Immutable value object for one parsed component definition.
 *
 * A definition is the `<component>/<component>.yaml` file that sits beside the
 * Twig template of a component. The object holds the parsed data. It does not
 * read files and it does not validate the shape of the fields tree.
 */
final class ComponentDefinition {

  /**
   * Constructs a ComponentDefinition.
   *
   * @param string $name
   *   The component name, for example `article-video`.
   * @param string $path
   *   The path of the YAML file, relative to the application root.
   * @param array<string, mixed> $data
   *   The parsed YAML document.
   */
  public function __construct(
    private readonly string $name,
    private readonly string $path,
    private readonly array $data,
  ) {}

  /**
   * Gets the component name.
   */
  public function getName(): string {
    return $this->name;
  }

  /**
   * Gets the YAML path, relative to the application root.
   */
  public function getPath(): string {
    return $this->path;
  }

  /**
   * Gets the whole parsed document.
   *
   * @return array<string, mixed>
   *   The parsed YAML.
   */
  public function getData(): array {
    return $this->data;
  }

  /**
   * Gets one top-level key of the document.
   *
   * @param string $key
   *   The key.
   * @param mixed $default
   *   The value to return when the key is missing.
   */
  public function get(string $key, mixed $default = NULL): mixed {
    return $this->data[$key] ?? $default;
  }

  /**
   * Gets the `fields` tree.
   *
   * @return array<string, mixed>
   *   The fields tree. Empty when the definition declares no fields.
   */
  public function getFields(): array {
    $fields = $this->data['fields'] ?? [];
    return is_array($fields) ? $fields : [];
  }

}
