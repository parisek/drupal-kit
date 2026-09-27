<?php

declare(strict_types=1);

namespace Drupal\drupal_kit\Services;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\VariationCacheFactoryInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;

/**
 * Resolves theme-declared "menu locations" to the menu that fills them.
 *
 * A theme declares named slots under `menu_locations:` in its
 * `<theme>.info.yml`, the same way it declares `regions:`. A site builder
 * assigns a menu to each slot, per language, on the form this module
 * provides. A theme then reads a slot's items as data — see items() — the
 * same way `region_items` in arkero reads a block's items today. That block
 * pattern is the antipattern this service replaces: a "menu_block" placed in
 * a region only to pick a menu is a menu picker wearing a block, not a real
 * block.
 *
 * Assignment lives in one config object, `drupal_kit.menu_locations`, keyed
 * `locations.<theme>.<slot>.<langcode>`. A theme is included in the key
 * because two themes on one site (default + admin, or a multi-brand site)
 * can declare the same slot name with unrelated content.
 */
class MenuLocations {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected ThemeExtensionList $themeExtensionList,
    protected LanguageManagerInterface $languageManager,
    protected EntityHelper $entityHelper,
    protected VariationCacheFactoryInterface $variationCacheFactory,
  ) {}

  /**
   * The slots a theme declares, machine name => label.
   *
   * @param string|null $theme
   *   The theme to read. NULL reads the site's default (frontend) theme —
   *   the same theme a page render uses, regardless of which theme renders
   *   the admin form calling this method.
   *
   * @return array<string, string>
   *   Slot machine name => human label, in declaration order. Empty when
   *   the theme declares no `menu_locations` key, exactly like a theme with
   *   no `regions` key has no regions.
   */
  public function slots(?string $theme = NULL): array {
    $theme ??= $this->defaultTheme();
    $info = $this->themeExtensionList->getExtensionInfo($theme);

    return $info['menu_locations'] ?? [];
  }

  /**
   * The menu assigned to one slot, for one language.
   *
   * @param string $slot
   *   The slot machine name, as declared by the theme.
   * @param string|null $langcode
   *   The language to resolve for. NULL resolves the current content
   *   language.
   * @param string|null $theme
   *   The theme the slot belongs to. NULL reads the site's default theme.
   *
   * @return string|null
   *   The assigned menu's machine name, or NULL when no menu is assigned —
   *   neither for the requested language nor for the site's default
   *   language, which is the fallback (see class docs).
   */
  public function menuName(string $slot, ?string $langcode = NULL, ?string $theme = NULL): ?string {
    $theme ??= $this->defaultTheme();
    $langcode ??= $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId();
    $assignments = $this->configFactory->get('drupal_kit.menu_locations')
      ->get("locations.$theme.$slot") ?? [];

    if (!empty($assignments[$langcode])) {
      return $assignments[$langcode];
    }

    // Fall back to the site's default language's menu. A language added
    // after the slot was configured, or one a builder skipped, would
    // otherwise render nothing rather than the site's usual content.
    $default_langcode = $this->languageManager->getDefaultLanguage()->getId();
    return $assignments[$default_langcode] ?? NULL;
  }

  /**
   * The menu items assigned to a slot, in EntityHelper::getMenu()'s shape.
   *
   * Render-cached the way arkero's own `arkero_region_items()` cached a
   * block's items: building a menu tree on every request that misses the
   * page and dynamic page caches is the cost this replaces, and the cache
   * varies by exactly the same things — the assigned menu's own cache
   * metadata, plus language and the active trail. A max age of 0 from
   * either the assignment or the menu itself skips the cache write, so nothing
   * conditionally cacheable is cached as if it were not.
   *
   * @param string $slot
   *   The slot machine name.
   * @param \Drupal\Core\Cache\CacheableMetadata|null $collected
   *   Optional accumulator. When given, every cache tag, context and max-age
   *   this call depends on — the assignment config, the resolved menu, the
   *   active trail, the content-language context — is added to it. Pass the
   *   same instance across several slots (a header call, a footer call) to
   *   accumulate their combined metadata once, as arkero's preprocess does.
   * @param string|null $theme
   *   The theme the slot belongs to. NULL reads the site's default theme.
   *
   * @return array<int, array<string, mixed>>
   *   The slot's menu items, or an empty array when no menu is assigned or
   *   the assigned menu has no items for the current language.
   */
  public function items(string $slot, ?CacheableMetadata $collected = NULL, ?string $theme = NULL): array {
    $theme ??= $this->defaultTheme();
    $langcode = $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId();

    // The assignment itself is cacheable data: a config change must
    // invalidate every render this method fed, not only the ones taken from
    // a cache miss below.
    $assignment_metadata = (new CacheableMetadata())
      ->addCacheTags(['config:drupal_kit.menu_locations'])
      ->addCacheContexts(['languages:language_content']);

    $menu_name = $this->menuName($slot, $langcode, $theme);
    if ($menu_name === NULL) {
      $collected?->addCacheableDependency($assignment_metadata);
      return [];
    }

    $variation_cache = $this->variationCacheFactory->get('render');
    $keys = ['drupal_kit_menu_location', $theme, $slot];
    $initial = new CacheableMetadata();
    $hit = $variation_cache->get($keys, $initial);
    if ($hit) {
      // VariationCacheInterface::get() types its return as a plain object;
      // set() below is the only writer of this bin's entries, so its shape
      // is exactly the array stored there — data->items and data->cache,
      // the same two keys set() is given.
      /** @var object{data: array{items: array<int, array<string, mixed>>, cache: array{tags: string[], contexts: string[], max-age: int}}} $hit */
      $collected?->addCacheableDependency($assignment_metadata)
        ->addCacheableDependency(CacheableMetadata::createFromRenderArray(['#cache' => $hit->data['cache']]));
      return $hit->data['items'];
    }

    $items = $this->entityHelper->getMenu($menu_name);
    $menu_metadata = $this->entityHelper->collectCacheMetadata();
    $menu_metadata->addCacheTags([
      'config:system.menu.' . $menu_name,
      'menu_link_content_list',
    ]);
    $menu_metadata->addCacheContexts([
      'languages:language_content',
      'route.menu_active_trails:' . $menu_name,
    ]);

    $collected?->addCacheableDependency($assignment_metadata)
      ->addCacheableDependency($menu_metadata);

    if ($menu_metadata->getCacheMaxAge() !== 0) {
      $variation_cache->set($keys, [
        'items' => $items,
        'cache' => [
          'tags' => $menu_metadata->getCacheTags(),
          'contexts' => $menu_metadata->getCacheContexts(),
          'max-age' => $menu_metadata->getCacheMaxAge(),
        ],
      ], $menu_metadata, $initial);
    }

    return $items;
  }

  /**
   * The site's default (frontend) theme.
   *
   * Read from `system.theme:default` rather than the active theme, so a
   * call made from an admin route (a different active theme) still resolves
   * slots and assignments for the theme visitors actually see.
   */
  protected function defaultTheme(): string {
    return $this->configFactory->get('system.theme')->get('default');
  }

}
