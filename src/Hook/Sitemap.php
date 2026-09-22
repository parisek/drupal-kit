<?php

declare(strict_types=1);

namespace Drupal\drupal_kit\Hook;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\drupal_kit\Services\FrontPageSitemapFilter;
use Drupal\language\ConfigurableLanguageManagerInterface;

/**
 * Keeps the front page out of the sitemap under its node path.
 */
class Sitemap {

  public function __construct(
    protected LanguageManagerInterface $languageManager,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Implements hook_simple_sitemap_links_alter().
   *
   * Drops the front page's node path from the sitemap.
   *
   * `system.site` points page.front at a node, and that node usually has no
   * path alias, so simple_sitemap lists the same page twice: once as `/` and
   * once as `/node/N`. The second 301s to the first through the redirect
   * module's route normalizer, handing crawlers a redirect to a page the file
   * already contains.
   *
   * The hook is inert on sites without simple_sitemap, which is why the
   * module is not a dependency: nothing invokes this. The sitemap argument is
   * typed loosely for the same reason, so the signature does not name a class
   * that only exists once simple_sitemap is installed.
   */
  #[Hook('simple_sitemap_links_alter')]
  public function simpleSitemapLinksAlter(array &$links, object $sitemap): void {
    // Once, not once per link — the map is the same for every one of them,
    // and building it reads config for every language on the site.
    $fronts = $this->frontPages();

    foreach ($links as $key => $link) {
      $filtered = FrontPageSitemapFilter::filterLink($link, $fronts);
      if ($filtered === NULL) {
        unset($links[$key]);
        continue;
      }
      $links[$key] = $filtered;
    }
  }

  /**
   * The front-page path each language resolves to.
   *
   * `page.front` is translatable, so the answer is a map, not a value. Asking
   * once and applying that answer to every link is what the first version did
   * — see FrontPageSitemapFilter::matchingLangcodes().
   *
   * @return array<string, string>
   *   Front-page internal paths, keyed by langcode.
   */
  protected function frontPages(): array {
    // getEditable(), not get(). `\Drupal::config()` returns the IMMUTABLE
    // object, which already has the active language's override applied — so
    // on a site whose current config language overrides page.front, the
    // "untranslated" fallback would silently be that language's value, and
    // every language without its own override would inherit it. getEditable()
    // reads the stored value. (ConfigFactory::doGet(), $immutable.)
    $stored = (string) $this->configFactory
      ->getEditable('system.site')
      ->get('page.front');

    // instanceof, not isMultilingual() alone. getLanguageConfigOverride()
    // lives on ConfigurableLanguageManagerInterface, which only the language
    // module provides; LanguageManagerInterface has no such method. The two
    // conditions agree on every real site — the configurable manager is what
    // makes a site multilingual — but the type is what makes the call safe,
    // and a static analyser reads the interface rather than the coincidence.
    if (!$this->languageManager instanceof ConfigurableLanguageManagerInterface || !$this->languageManager->isMultilingual()) {
      // No language module, so there are no overrides to find and the stored
      // value is the whole map. The key is the langcode a link carries when
      // simple_sitemap did not attribute it to a language.
      return [LanguageInterface::LANGCODE_NOT_SPECIFIED => $stored];
    }

    $fronts = [];
    foreach (array_keys($this->languageManager->getLanguages()) as $langcode) {
      $override = $this->languageManager
        ->getLanguageConfigOverride($langcode, 'system.site')
        ->get('page.front');
      $fronts[$langcode] = (string) ($override ?? $stored);
    }

    return $fronts;
  }

}
