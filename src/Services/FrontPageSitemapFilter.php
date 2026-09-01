<?php

namespace Drupal\drupal_kit\Services;

/**
 * Decides whether a sitemap entry duplicates the front page.
 *
 * `system.site` normally points the front page at a node, and that node
 * usually has no path alias of its own. simple_sitemap then lists the same
 * page twice: once as `https://example.com/` and once as
 * `https://example.com/node/9`. The second entry 301s to the first through
 * the redirect module's route normalizer, so crawlers are handed a redirect
 * to a page the file already contains.
 *
 * Every PORTA site hits this: nineteen of them run simple_sitemap with a node
 * behind page.front, and none of them declares an alias for that node.
 *
 * Pure logic, no Drupal dependencies, so it is unit-testable without a
 * container. The caller supplies both paths; see
 * drupal_kit_simple_sitemap_links_alter().
 */
class FrontPageSitemapFilter {

  /**
   * Whether a sitemap path is the front page reached by its internal route.
   *
   * @param string $link_path
   *   The path simple_sitemap generated, for example `node/9`. It carries no
   *   leading slash, unlike the value stored in system.site.
   * @param string $front_path
   *   The configured front page, for example `/node/9`.
   *
   * @return bool
   *   TRUE when the entry should be dropped as a duplicate. The front page's
   *   own `/` entry is always kept: that one is canonical.
   */
  public static function isFrontDuplicate(string $link_path, string $front_path): bool {
    $front = ltrim(trim($front_path), '/');
    $link = ltrim(trim($link_path), '/');

    if ($front === '' || $link === '') {
      return FALSE;
    }

    return $link === $front;
  }

  /**
   * Which of $fronts name this path as their front page.
   *
   * `page.front` is translatable: a site can point each language at its own
   * node, so "is this the front page" has no single answer. The first version
   * asked `system.site` once, in whatever language context generation ran in,
   * and applied that answer to every link — on a site with per-language front
   * pages that deleted one language's node outright while leaving the other
   * language's duplicate in place.
   *
   * @param string $link_path
   *   The path simple_sitemap generated, for example `node/9`. It carries no
   *   leading slash, unlike the value stored in system.site.
   * @param array<string, string> $fronts
   *   Configured front page per langcode.
   *
   * @return string[]
   *   The langcodes whose front page this path is. Empty means keep the link
   *   untouched.
   */
  public static function matchingLangcodes(string $link_path, array $fronts): array {
    $matches = [];
    foreach ($fronts as $langcode => $front) {
      if (self::isFrontDuplicate($link_path, (string) $front)) {
        $matches[] = (string) $langcode;
      }
    }

    return $matches;
  }

  /**
   * Rewrites one link, or marks it for removal.
   *
   * A link carries a primary language plus the `alternate_urls` map that
   * becomes its `hreflang` block. The two are decided separately, because a
   * path can be the front page in one language and an ordinary page in
   * another:
   *
   * - The PRIMARY language decides the link's fate. When this path is that
   *   language's front page, the entry duplicates `/` and goes. Languages it
   *   was merely an alternate for keep their own entries, which simple_sitemap
   *   emits separately.
   * - The ALTERNATES are pruned per language either way. Leaving a front-page
   *   URL in another language's `hreflang` block re-advertises exactly the URL
   *   this filter exists to withhold.
   *
   * @param array<string, mixed> $link
   *   One simple_sitemap link.
   * @param array<string, string> $fronts
   *   Configured front page per langcode.
   *
   * @return array<string, mixed>|null
   *   The rewritten link, or NULL when it should be dropped entirely.
   */
  public static function filterLink(array $link, array $fronts): ?array {
    $path = (string) ($link['meta']['path'] ?? '');
    if ($path === '') {
      return $link;
    }

    $matches = self::matchingLangcodes($path, $fronts);
    if ($matches === []) {
      return $link;
    }

    // No langcode means a monolingual site, or a link simple_sitemap did not
    // attribute to a language. Any match then speaks for the whole link.
    $langcode = isset($link['langcode']) ? (string) $link['langcode'] : NULL;
    if ($langcode === NULL || in_array($langcode, $matches, TRUE)) {
      return NULL;
    }

    if (isset($link['alternate_urls']) && is_array($link['alternate_urls'])) {
      $link['alternate_urls'] = array_diff_key(
        $link['alternate_urls'],
        array_flip($matches),
      );
    }

    return $link;
  }

}
