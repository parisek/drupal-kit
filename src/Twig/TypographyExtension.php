<?php

declare(strict_types=1);

namespace Drupal\custom_components\Twig;

use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\Core\Theme\ThemeManagerInterface;
use Parisek\Twig\TypographyExtension as UpstreamTypographyExtension;
use Symfony\Component\Yaml\Yaml;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Drupal-side wrapper for parisek/twig-typography.
 *
 * Resolves the active theme's `static/typography.yml`, parses it once per
 * theme, and delegates filtering to a cached upstream extension instance.
 * Also pass-through Drupal render arrays without processing.
 */
class TypographyExtension extends AbstractExtension {

  /**
   * Per-theme cache of upstream extensions. Keyed by theme machine name.
   *
   * @var array<string, \Parisek\Twig\TypographyExtension>
   */
  private array $cache = [];

  public function __construct(
    private readonly ThemeManagerInterface $themeManager,
    private readonly ExtensionPathResolver $extensionPathResolver,
    private readonly string $appRoot,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFilters(): array {
    return [
      new TwigFilter(
        'typography',
        [$this, 'applyTypography'],
        ['is_safe' => ['html']],
      ),
    ];
  }

  /**
   * Apply the typography filter, with render-array pass-through.
   *
   * @param mixed $string
   *   The string to filter, or a render array (which is returned unchanged).
   * @param array<string, mixed> $arguments
   *   Optional per-call overrides for PHP_Typography settings.
   * @param bool $useDefaults
   *   Whether to load the upstream Settings(true) defaults.
   *
   * @return mixed
   *   Filtered string, or the original render array.
   */
  public function applyTypography(mixed $string, array $arguments = [], bool $useDefaults = TRUE): mixed {
    if (\is_array($string)) {
      return $string;
    }
    return $this->upstreamForActiveTheme()->applyTypography($string, $arguments, $useDefaults);
  }

  /**
   * Gets (and lazily builds) the upstream extension for the active theme.
   */
  private function upstreamForActiveTheme(): UpstreamTypographyExtension {
    $themeName = $this->themeManager->getActiveTheme()->getName();
    if (!isset($this->cache[$themeName])) {
      $themePath = $this->extensionPathResolver->getPath('theme', $themeName);
      $path = \rtrim($this->appRoot, '/') . '/' . \ltrim($themePath, '/') . '/static/typography.yml';
      $parsed = \file_exists($path) ? Yaml::parseFile($path) : NULL;
      $config = \is_array($parsed) ? $parsed : [];
      $this->cache[$themeName] = new UpstreamTypographyExtension($config);
    }
    return $this->cache[$themeName];
  }

}
