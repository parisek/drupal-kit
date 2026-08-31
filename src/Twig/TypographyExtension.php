<?php

declare(strict_types=1);

namespace Drupal\drupal_kit\Twig;

use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
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
 *
 * The upstream extension is handed a locale resolver so `|typography` applies
 * the per-language tables (quote style, dash convention, single-character word
 * spacing, ...) that `parisek/twig-typography` ^1.3 ships. Without one,
 * `TypographyExtension::localeCandidates()` returns an empty array and the
 * whole `languages:` layer is inert — the language-neutral house defaults are
 * all that ever apply. Mirrors `StarterBase::typography_locale_resolver()` in
 * parisek/timber-kit, the WordPress-side sibling of this class.
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
    private readonly LanguageManagerInterface $languageManager,
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
      $this->cache[$themeName] = new UpstreamTypographyExtension(
        $config,
        $this->localeResolver(),
      );
    }
    return $this->cache[$themeName];
  }

  /**
   * The locale resolver handed to the upstream extension.
   *
   * Reports the **content** language, not the interface language. The two
   * differ exactly where it matters: an editor whose account language is Czech
   * previewing an English node gets `TYPE_INTERFACE` = `cs` while the text
   * being typeset is English, which would apply Czech quotes and Czech
   * single-character word spacing to English prose. `TYPE_CONTENT` follows the
   * content being rendered. Drupal falls back to the interface language when
   * content language negotiation is not configured, so a monolingual site is
   * unaffected. This is the same distinction timber-kit documents for
   * `get_locale()` vs `determine_locale()`.
   *
   * The returned closure is evaluated per `applyTypography()` call rather than
   * once at construction, so a single cached upstream instance serves every
   * language in a request — which is why the cache above stays keyed on the
   * theme alone and needs no language component.
   *
   * `protected` so a site whose language detection does not go through
   * `language_manager` (a custom per-entity language field, say) can subclass
   * and swap it without forking the wrapper. It is never called from Twig, so
   * it does not need to be public.
   *
   * @return callable(): string
   *   Zero-argument callable returning the current content language code.
   */
  protected function localeResolver(): callable {
    return fn (): string => $this->languageManager
      ->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)
      ->getId();
  }

}
