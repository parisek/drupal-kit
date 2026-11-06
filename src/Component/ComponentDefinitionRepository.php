<?php

declare(strict_types=1);

namespace Drupal\drupal_kit\Component;

use Drupal\Component\Serialization\Exception\InvalidDataTypeException;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ExtensionList;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\components\Template\ComponentsRegistry;

/**
 * Reads `<component>/<component>.yaml` beside the Twig template.
 *
 * The Components registry decides which template wins, so theme and base theme
 * precedence is the Twig precedence. The YAML file next to the winning
 * template is the definition.
 */
class ComponentDefinitionRepository implements ComponentDefinitionRepositoryInterface {

  /**
   * Cache tag that a theme registry rebuild invalidates.
   *
   * The Components module invalidates it from hook_theme(), which runs on
   * every cache rebuild.
   */
  public const REGISTRY_TAG = 'components_registry';

  /**
   * Cache tag of this repository.
   */
  public const CACHE_TAG = 'drupal_kit_component_definitions';

  /**
   * The static cache, keyed by cache id.
   *
   * @var array<string, \Drupal\drupal_kit\Component\ComponentDefinition|false>
   */
  private array $loaded = [];

  /**
   * Constructs a ComponentDefinitionRepository.
   *
   * @param \Drupal\components\Template\ComponentsRegistry|null $registry
   *   The Components registry. NULL means no component has a definition.
   * @param \Drupal\Core\Theme\ThemeManagerInterface $themeManager
   *   The theme manager. The active theme is part of the cache id.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The discovery cache bin.
   * @param \Drupal\Core\Extension\ExtensionList $themeList
   *   The theme list, to find the theme that owns a template.
   * @param \Drupal\Core\Extension\ExtensionList $moduleList
   *   The module list, for components a module supplies.
   * @param string $root
   *   The application root. Registry paths are relative to it.
   * @param array<string, string> $aliases
   *   Bundle to component name overrides, for example `html` to `content`.
   * @param string[] $exclusions
   *   Bundles that have no component.
   * @param string $namespace
   *   The Twig namespace of the components, without `@`.
   */
  public function __construct(
    private readonly ?ComponentsRegistry $registry,
    private readonly ThemeManagerInterface $themeManager,
    private readonly CacheBackendInterface $cache,
    private readonly ExtensionList $themeList,
    private readonly ExtensionList $moduleList,
    private readonly string $root,
    private readonly array $aliases = [],
    private readonly array $exclusions = [],
    private readonly string $namespace = 'component',
  ) {}

  /**
   * {@inheritdoc}
   */
  public function isValidComponentName(string $name): bool {
    return (bool) preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D', $name);
  }

  /**
   * {@inheritdoc}
   */
  public function resolveComponentName(string $bundle): ?string {
    if (in_array($bundle, $this->exclusions, TRUE)) {
      return NULL;
    }
    $name = $this->aliases[$bundle] ?? str_replace('_', '-', $bundle);
    return $this->isValidComponentName($name) ? $name : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function loadForBundle(string $bundle): ?ComponentDefinition {
    $name = $this->resolveComponentName($bundle);
    return $name === NULL ? NULL : $this->load($name);
  }

  /**
   * {@inheritdoc}
   */
  public function load(string $component): ?ComponentDefinition {
    // The name check comes first. No path is built from a bad name.
    if (!$this->isValidComponentName($component)) {
      return NULL;
    }

    $cid = 'drupal_kit.component_definition:' . $this->themeManager->getActiveTheme()->getName() . ':' . $component;
    if (array_key_exists($cid, $this->loaded)) {
      return $this->unpack($this->loaded[$cid]);
    }

    if ($cached = $this->cache->get($cid)) {
      $entry = $cached->data;
    }
    else {
      try {
        $definition = $this->read($component);
        $entry = $definition ? [
          'name' => $definition->getName(),
          'path' => $definition->getPath(),
          'data' => $definition->getData(),
          'provider' => $definition->getProvider(),
        ] : ['missing' => TRUE];
      }
      catch (InvalidComponentDefinitionException $e) {
        $entry = ['invalid' => $e->getMessage()];
      }
      // A missing or malformed definition is cached too. The tag clears it on
      // the next cache rebuild, like the Twig registry.
      $this->cache->set($cid, $entry, CacheBackendInterface::CACHE_PERMANENT, [
        self::REGISTRY_TAG,
        self::CACHE_TAG,
      ]);
    }

    $this->loaded[$cid] = $entry;
    return $this->unpack($entry);
  }

  /**
   * Builds a definition from its cached form, or throws for a bad entry.
   */
  private function unpack(mixed $entry): ?ComponentDefinition {
    if (is_array($entry) && isset($entry['invalid'])) {
      throw new InvalidComponentDefinitionException((string) $entry['invalid']);
    }
    if (!is_array($entry) || !isset($entry['name'], $entry['path'], $entry['data']) || !is_array($entry['data'])) {
      return NULL;
    }
    return new ComponentDefinition($entry['name'], $entry['path'], $entry['data'], $entry['provider'] ?? NULL);
  }

  /**
   * Reads and parses the YAML file of a component.
   */
  private function read(string $component): ?ComponentDefinition {
    if ($this->registry === NULL) {
      return NULL;
    }
    $template = $this->registry->getTemplate('@' . $this->namespace . '/' . $component . '/' . $component . '.twig');
    if ($template === NULL || !str_ends_with($template, '.twig')) {
      return NULL;
    }

    $path = substr($template, 0, -strlen('.twig')) . '.yaml';
    $file = $this->root . '/' . ltrim($path, '/');
    if (!is_file($file) || !is_readable($file)) {
      return NULL;
    }

    try {
      $data = Yaml::decode((string) file_get_contents($file));
    }
    catch (InvalidDataTypeException $e) {
      throw new InvalidComponentDefinitionException(sprintf('The component definition %s is not valid YAML: %s', $path, $e->getMessage()), 0, $e);
    }

    if (!is_array($data) || (isset($data['fields']) && !is_array($data['fields']))) {
      throw new InvalidComponentDefinitionException(sprintf('The component definition %s must be a map, and its "fields" key must be a map too.', $path));
    }

    return new ComponentDefinition($component, $path, $data, $this->findOwner($path));
  }

  /**
   * Finds the module or theme whose directory holds a path.
   *
   * The deepest directory wins, so a sub theme that lives below its base theme
   * is not mistaken for the base theme.
   */
  private function findOwner(string $path): ?string {
    $owner = NULL;
    $length = -1;
    foreach ([$this->themeList, $this->moduleList] as $list) {
      foreach ($list->getList() as $name => $extension) {
        $directory = rtrim($extension->getPath(), '/') . '/';
        if (str_starts_with($path, $directory) && strlen($directory) > $length) {
          $owner = (string) $name;
          $length = strlen($directory);
        }
      }
    }
    return $owner;
  }

}
