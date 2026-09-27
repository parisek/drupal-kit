<?php

declare(strict_types=1);

namespace Drupal\drupal_kit\Services;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\VariationCacheFactoryInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\Theme\ThemeManagerInterface;

/**
 * Resolves theme-declared "menu locations" to the menu that fills them.
 *
 * A theme declares named slots under `menu_locations:` in its
 * `<theme>.info.yml`, the same way it declares `regions:`. A site builder
 * assigns ONE menu to each slot on the form this module provides. A theme
 * then reads a slot's items as data — see items() — the same way
 * `region_items` in arkero reads a block's items today. That block pattern
 * is the antipattern this service replaces: a "menu_block" placed in a
 * region only to pick a menu is a menu picker wearing a block, not a real
 * block.
 *
 * The DEFAULT-language assignment is one menu per slot, not one menu per
 * slot per language — a menu link (`menu_link_content`) is itself
 * content-translatable, and `EntityHelper::getMenu()` already returns the
 * translation for the current content language, which is how every other
 * menu-driven part of this module has always worked. A menu-per-language
 * ASSIGNMENT (as opposed to a menu-per-language CONTENT) is a second
 * translation mechanism sitting next to the real one, and the two drift: a
 * downstream theme's own `header_menu` config once carried `main-en` with
 * the footer address translated as "ARKERO, DE" alongside `main`'s own
 * German translation reading "ARKERO GmbH, DE" — two independent copies of
 * the same fact, disagreeing.
 *
 * A site that genuinely needs a DIFFERENT menu per language — not just
 * different translated links in the same menu — gets that through
 * `config_translation`'s native, optional per-language config override, not
 * through a second key in this config's own shape. `config/schema/
 * drupal_kit.schema.yml` marks the slot's value `translatable: true` with a
 * `form_element_class` (`\Drupal\drupal_kit\FormElement\MenuSelect`) that
 * renders as a menu SELECT on the "Translate" tab config_translation adds
 * to the menu locations form (via `drupal_kit.config_translation.yml`) once
 * that module is installed. `menuName()` and `items()` read the assignment
 * through the injected config factory, exactly like any other config value
 * a project might translate — `\Drupal\Core\Config\ConfigFactory::get()`
 * transparently applies a matching language override (if one exists) via
 * `LanguageConfigFactoryOverride`, with no special-casing needed here.
 *
 * That override follows the **interface** language
 * (`languages:language_interface`), not the content language this module's
 * menu ITEMS vary by — `LanguageConfigFactoryOverride::getCacheableMetadata()`
 * declares that context, and `LanguageRequestSubscriber` sets the override
 * language from `getCurrentLanguage()`'s default argument
 * (`LanguageInterface::TYPE_INTERFACE`) on every request. On a site like
 * arkero, where content and interface language are both negotiated from the
 * same URL path prefix, the two are always equal in practice and this
 * distinction is invisible; a site where they can diverge (a multilingual
 * admin UI over monolingual content, for instance) would see the SLOT
 * ASSIGNMENT follow the interface language while the resolved menu's ITEMS
 * still follow the content language, because those are two different
 * translation mechanisms answering two different questions.
 *
 * `config_translation` stays entirely OPTIONAL. Nothing in this class
 * requires it, checks for it, or behaves differently based on whether it is
 * installed — `ConfigFactory::get()` returns the plain, unoverridden config
 * when no language override exists, which is exactly the default-language
 * behavior a site without `config_translation` gets. `MenuSelect` is loaded
 * only by `config_translation`'s own code, and only when that module scans
 * for a `form_element_class` — see its own class docblock.
 *
 * Assignment lives in one config object, `drupal_kit.menu_locations`, keyed
 * `locations.<theme>.<slot>`. A theme is included in the key because two
 * themes on one site (default + admin, or a multi-brand site) can declare
 * the same slot name with unrelated content.
 */
class MenuLocations {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected ThemeExtensionList $themeExtensionList,
    protected EntityHelper $entityHelper,
    protected VariationCacheFactoryInterface $variationCacheFactory,
    protected ThemeManagerInterface $themeManager,
  ) {}

  /**
   * The slots a theme declares, machine name => label.
   *
   * @param string|null $theme
   *   The theme to read. NULL reads the currently active theme — the theme
   *   negotiation picked for this request, which is not always the site's
   *   default theme (an admin theme, a domain-negotiated theme, …).
   *
   * @return array<string, string>
   *   Slot machine name => human label, in declaration order. Empty when
   *   the theme declares no `menu_locations` key, exactly like a theme with
   *   no `regions` key has no regions.
   */
  public function slots(?string $theme = NULL): array {
    $theme ??= $this->activeTheme();
    $info = $this->themeExtensionList->getExtensionInfo($theme);

    return $info['menu_locations'] ?? [];
  }

  /**
   * The menu assigned to one slot, in the current override language.
   *
   * @param string $slot
   *   The slot machine name, as declared by the theme.
   * @param string|null $theme
   *   The theme the slot belongs to. NULL reads the currently active theme.
   * @param \Drupal\Core\Cache\CacheableMetadata|null $collected
   *   Optional accumulator. When given, the config object's own cacheable
   *   metadata — its `config:drupal_kit.menu_locations` tag, and
   *   `languages:language_interface` when a config_translation override is
   *   active for the current interface language — is added to it.
   *
   * @return string|null
   *   The assigned menu's machine name, or NULL when no menu is assigned.
   */
  public function menuName(string $slot, ?string $theme = NULL, ?CacheableMetadata $collected = NULL): ?string {
    $theme ??= $this->activeTheme();

    // ConfigFactory::get() transparently returns the current interface
    // language's override when config_translation (or any other
    // LanguageConfigFactoryOverride consumer) has one — no special-casing
    // needed here. See this class's own docblock for why that is the
    // INTERFACE language, not the content language items() varies by.
    $config = $this->configFactory->get('drupal_kit.menu_locations');
    $collected?->addCacheableDependency(CacheableMetadata::createFromObject($config));

    return $config->get("locations.$theme.$slot") ?: NULL;
  }

  /**
   * The menu items assigned to a slot, in EntityHelper::getMenu()'s shape.
   *
   * Render-cached the way arkero's own `arkero_region_items()` cached a
   * block's items: building a menu tree on every request that misses the
   * page and dynamic page caches is the cost this replaces, and the cache
   * varies by exactly the same things — the assigned menu's own cache
   * metadata, which includes `languages:language_content` (menu links are
   * content-translatable, so the SAME menu name can render two different
   * translations) and the active trail. A max age of 0 from either the
   * assignment or the menu itself skips the cache write, so nothing
   * conditionally cacheable is cached as if it were not.
   *
   * The cache key includes the RESOLVED menu name, not just theme and slot.
   * Two things depend on that: reassigning a slot from menu A to menu B
   * must not keep serving A's items from an entry keyed only on theme+slot,
   * and the stored entry's own tags include `config:drupal_kit.menu_locations`
   * so an assignment change also invalidates whatever entry the OLD menu
   * name is still sitting under, rather than leaving an orphaned entry that
   * a very unlucky reassignment sequence (A to B, then back to A) could
   * serve stale.
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
   *   The theme the slot belongs to. NULL reads the currently active theme.
   *
   * @return array<int, array<string, mixed>>
   *   The slot's menu items, or an empty array when no menu is assigned or
   *   the assigned menu has no items for the current language.
   */
  public function items(string $slot, ?CacheableMetadata $collected = NULL, ?string $theme = NULL): array {
    $theme ??= $this->activeTheme();

    // The assignment itself is cacheable data: a config change (or a
    // config_translation override being added, changed or removed) must
    // invalidate every render this method fed, not only the ones taken from
    // a cache miss below. Sourced from the config object itself — via
    // menuName()'s own $collected parameter — rather than hand-built, so it
    // automatically carries languages:language_interface whenever a
    // language-override PROVIDER is active at all (core's own
    // LanguageConfigFactoryOverride adds that context based on whether an
    // override LANGUAGE is set, not on whether THIS config happens to have
    // an override yet — a translation could be added later), and nothing
    // extra on a site with no such provider installed.
    $assignment_metadata = new CacheableMetadata();
    $menu_name = $this->menuName($slot, $theme, $assignment_metadata);
    if ($menu_name === NULL) {
      $collected?->addCacheableDependency($assignment_metadata);
      return [];
    }

    $variation_cache = $this->variationCacheFactory->get('render');
    $keys = ['drupal_kit_menu_location', $theme, $slot, $menu_name];
    $initial = new CacheableMetadata();
    $hit = $variation_cache->get($keys, $initial);
    if ($hit) {
      // VariationCacheInterface::get() types its return as a plain object;
      // set() below is the only writer of this bin's entries, so its shape
      // is exactly the array stored there — data->items and data->cache,
      // the same two keys set() is given.
      /** @var object{data: array{items: array<int, array<string, mixed>>, cache: array{tags: string[], contexts: string[], max-age: int}}} $hit */
      $collected?->addCacheableDependency(CacheableMetadata::createFromRenderArray(['#cache' => $hit->data['cache']]));
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

    // merge() returns a NEW object rather than mutating $assignment_metadata
    // — the CacheableMetadata::merge() pitfall arkero's own preprocess
    // function documents. The combined metadata, not just $menu_metadata, is
    // what gets stored: the cache ENTRY must carry
    // `config:drupal_kit.menu_locations` too, or saving the assignment form
    // never invalidates an already-cached entry.
    $full_metadata = $assignment_metadata->merge($menu_metadata);

    $collected?->addCacheableDependency($full_metadata);

    if ($full_metadata->getCacheMaxAge() !== 0) {
      $variation_cache->set($keys, [
        'items' => $items,
        'cache' => [
          'tags' => $full_metadata->getCacheTags(),
          'contexts' => $full_metadata->getCacheContexts(),
          'max-age' => $full_metadata->getCacheMaxAge(),
        ],
      ], $full_metadata, $initial);
    }

    return $items;
  }

  /**
   * The currently active theme.
   *
   * Read through theme.manager, not `system.theme:default` — a negotiated
   * frontend theme (multi-brand domain access, a non-default admin theme
   * building its own render) must read its OWN declared slots and its own
   * assignment, not the site's configured default. `MenuLocationsForm`
   * deliberately does the opposite (always edits `system.theme:default`,
   * see its own docblock) — an admin editing menu locations expects to
   * configure the theme visitors normally see, not whichever theme happens
   * to be active on the admin route they are looking at.
   */
  protected function activeTheme(): string {
    return $this->themeManager->getActiveTheme()->getName();
  }

}
