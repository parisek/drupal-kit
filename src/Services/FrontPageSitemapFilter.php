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

}
