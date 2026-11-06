<?php

declare(strict_types=1);

namespace Drupal\drupal_kit_paragraphs\Mapper;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\drupal_kit_paragraphs\Attribute\ParagraphMapper;

/**
 * Discovers paragraph mapper plugins in modules and themes.
 *
 * Core puts the PSR-4 namespace of every installed theme, next to the modules,
 * in `container.namespaces`. Discovery therefore finds
 * `Drupal\THEME\Plugin\ParagraphMapper\*` with no extra code. Two things need
 * care, and this class handles both:
 *
 * - Core checks the provider of a definition with moduleExists(). A theme is
 *   not a module, so the check would drop every theme plugin. providerExists()
 *   accepts an installed theme too.
 * - Discovery sees all installed themes. A plugin of a theme that is not in
 *   the active chain must not run, so getMappersForComponent() filters at
 *   run time. The definition cache stays theme-independent.
 */
class ParagraphMapperManager extends DefaultPluginManager implements ParagraphMapperManagerInterface {

  /**
   * Constructs a ParagraphMapperManager.
   *
   * @param \Traversable $namespaces
   *   The root namespaces of modules and themes.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   The discovery cache bin.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\Core\Extension\ThemeHandlerInterface $themeHandler
   *   The theme handler.
   * @param \Drupal\Core\Theme\ThemeManagerInterface $themeManager
   *   The theme manager.
   */
  public function __construct(
    \Traversable $namespaces,
    CacheBackendInterface $cache_backend,
    ModuleHandlerInterface $module_handler,
    protected ThemeHandlerInterface $themeHandler,
    protected ThemeManagerInterface $themeManager,
  ) {
    parent::__construct(
      'Plugin/ParagraphMapper',
      $namespaces,
      $module_handler,
      ParagraphMapperInterface::class,
      ParagraphMapper::class,
    );
    $this->alterInfo('drupal_kit_paragraph_mapper_info');
    $this->setCacheBackend($cache_backend, 'drupal_kit_paragraph_mapper_plugins');
  }

  /**
   * {@inheritdoc}
   */
  protected function providerExists($provider) {
    return parent::providerExists($provider) || $this->themeHandler->themeExists($provider);
  }

  /**
   * {@inheritdoc}
   */
  public function getMappersForComponent(string $component): array {
    $definitions = static::selectDefinitions(
      $this->getDefinitions(),
      $component,
      $this->getActiveThemeNames(),
      fn (string $provider): bool => $this->themeHandler->themeExists($provider),
    );

    $mappers = [];
    foreach (array_keys($definitions) as $plugin_id) {
      $mappers[$plugin_id] = $this->createInstance($plugin_id);
    }
    return $mappers;
  }

  /**
   * Selects and orders the definitions of one component.
   *
   * @param array<string, array<string, mixed>> $definitions
   *   All plugin definitions.
   * @param string $component
   *   The component name.
   * @param string[] $active_themes
   *   The themes whose plugins may run.
   * @param callable $is_theme
   *   Tells if a provider is a theme. A provider that is no theme is a module.
   *
   * @return array<string, array<string, mixed>>
   *   The matching definitions in run order: priority ascending, then plugin
   *   ID ascending.
   */
  public static function selectDefinitions(array $definitions, string $component, array $active_themes, callable $is_theme): array {
    $selected = [];
    foreach ($definitions as $plugin_id => $definition) {
      if (($definition['component'] ?? NULL) !== $component) {
        continue;
      }
      $provider = $definition['provider'] ?? '';
      if ($is_theme($provider) && !in_array($provider, $active_themes, TRUE)) {
        continue;
      }
      $selected[$plugin_id] = $definition;
    }

    uksort($selected, static function (string $a, string $b) use ($selected): int {
      return [(int) ($selected[$a]['priority'] ?? 0), $a] <=> [(int) ($selected[$b]['priority'] ?? 0), $b];
    });
    return $selected;
  }

  /**
   * Gets the themes whose plugins may run.
   *
   * The active theme and its base themes come first. The default theme and its
   * base themes follow, because the Components registry falls back to the
   * default theme for a template the active theme lacks, for example when an
   * administration theme is active.
   *
   * @return string[]
   *   The theme machine names.
   */
  protected function getActiveThemeNames(): array {
    $names = [];
    $roots = [$this->themeManager->getActiveTheme()->getName()];
    if ($default = $this->themeHandler->getDefault()) {
      $roots[] = $default;
    }
    foreach ($roots as $root) {
      $names[] = $root;
      if ($this->themeHandler->themeExists($root)) {
        $names = array_merge($names, array_keys($this->themeHandler->getTheme($root)->base_themes ?? []));
      }
    }
    return array_values(array_unique($names));
  }

}
