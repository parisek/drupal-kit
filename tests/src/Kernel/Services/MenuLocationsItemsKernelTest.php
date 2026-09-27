<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_kit\Kernel\Services;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Language\LanguageInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\drupal_kit\Services\EntityHelper;
use Drupal\drupal_kit\Services\MenuLocations;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\system\Entity\Menu;

/**
 * Behavioral tests for MenuLocations::items() against a real menu tree.
 *
 * The slots() and menuName() methods are pure-ish and covered with mocks in
 * MenuLocationsTest. items() needs the real menu tree builder, the config
 * system and the render cache, so it belongs here.
 *
 * @coversDefaultClass \Drupal\drupal_kit\Services\MenuLocations
 * @group drupal_kit
 */
class MenuLocationsItemsKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'drupal_kit',
    'system',
    'user',
    'menu_link_content',
    'link',
    'field',
    'text',
    'language',
  ];

  /**
   * The service under test.
   */
  protected MenuLocations $menuLocations;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('menu_link_content');
    $this->menuLocations = $this->container->get('drupal_kit.menu_locations');
  }

  /**
   * Assigns a menu to a slot for the given theme in raw config.
   *
   * One menu per slot — no language dimension. See MenuLocations' own class
   * docblock for why: menu_link_content is itself content-translatable,
   * and a config-level menu-per-language assignment on top of that is a
   * second translation mechanism for the same fact.
   */
  protected function assign(string $theme, string $slot, string $menu_name): void {
    $config = $this->config('drupal_kit.menu_locations');
    $locations = $config->get('locations') ?? [];
    $locations[$theme][$slot] = $menu_name;
    $config->set('locations', $locations)->save();
  }

  /**
   * @covers ::items
   */
  public function testUnassignedSlotReturnsEmptyItemsButStillTagsTheConfig(): void {
    $collected = new CacheableMetadata();
    $items = $this->menuLocations->items('header_menu', $collected, 'stark');

    $this->assertSame([], $items);
    $this->assertContains('config:drupal_kit.menu_locations', $collected->getCacheTags());
  }

  /**
   * An assigned slot returns menu items with full cache metadata.
   *
   * The returned shape matches EntityHelper::getMenu() exactly — this
   * service is a router in front of it, not a reshaping layer.
   *
   * @covers ::items
   */
  public function testAssignedSlotReturnsMenuItemsWithFullCacheMetadata(): void {
    Menu::create(['id' => 'main', 'label' => 'Main'])->save();
    MenuLinkContent::create([
      'menu_name' => 'main',
      'title' => 'Home',
      'link' => ['uri' => 'internal:/'],
      'enabled' => 1,
    ])->save();
    $this->assign('stark', 'header_menu', 'main');

    $collected = new CacheableMetadata();
    $items = $this->menuLocations->items('header_menu', $collected, 'stark');

    $this->assertNotEmpty($items);
    $this->assertSame('Home', reset($items)['title']);

    $tags = $collected->getCacheTags();
    $this->assertContains('config:drupal_kit.menu_locations', $tags);
    $this->assertContains('config:system.menu.main', $tags);
    $this->assertContains('menu_link_content_list', $tags);

    $contexts = $collected->getCacheContexts();
    $this->assertContains('languages:language_content', $contexts);
    $this->assertContains('route.menu_active_trails:main', $contexts);
  }

  /**
   * A render-cache hit returns the same items as the build that fed it.
   *
   * The render-cache hit path (variation_cache_factory) returns the same
   * items and the same cache tags as the miss that populated it, so a
   * cached slot is indistinguishable from a freshly built one to a caller.
   *
   * @covers ::items
   */
  public function testRenderCacheHitReturnsSameItemsAsTheOriginalBuild(): void {
    Menu::create(['id' => 'main', 'label' => 'Main'])->save();
    MenuLinkContent::create([
      'menu_name' => 'main',
      'title' => 'Home',
      'link' => ['uri' => 'internal:/'],
      'enabled' => 1,
    ])->save();
    $this->assign('stark', 'header_menu', 'main');

    $first_collected = new CacheableMetadata();
    $first = $this->menuLocations->items('header_menu', $first_collected, 'stark');

    $second_collected = new CacheableMetadata();
    $second = $this->menuLocations->items('header_menu', $second_collected, 'stark');

    $this->assertSame($first, $second);
    $this->assertSame($first_collected->getCacheTags(), $second_collected->getCacheTags());
    $this->assertSame($first_collected->getCacheContexts(), $second_collected->getCacheContexts());
    $this->assertSame($first_collected->getCacheMaxAge(), $second_collected->getCacheMaxAge());
  }

  /**
   * Reassigning a slot to a different menu is reflected immediately.
   *
   * Regression test: the cache used to be keyed on theme+slot only, so a
   * slot reassigned from menu A to menu B kept serving A's items — the
   * assignment config tag was never part of the stored entry, and the key
   * did not change either. Both are fixed: the key now includes the
   * RESOLVED menu name, and the stored entry's own tags include
   * `config:drupal_kit.menu_locations`.
   *
   * @covers ::items
   */
  public function testReassigningSlotReturnsTheNewMenusItems(): void {
    Menu::create(['id' => 'menu_a', 'label' => 'A'])->save();
    Menu::create(['id' => 'menu_b', 'label' => 'B'])->save();
    MenuLinkContent::create([
      'menu_name' => 'menu_a',
      'title' => 'From A',
      'link' => ['uri' => 'internal:/a'],
      'enabled' => 1,
    ])->save();
    MenuLinkContent::create([
      'menu_name' => 'menu_b',
      'title' => 'From B',
      'link' => ['uri' => 'internal:/b'],
      'enabled' => 1,
    ])->save();

    $this->assign('stark', 'header_menu', 'menu_a');
    $before = $this->menuLocations->items('header_menu', NULL, 'stark');
    $this->assertNotEmpty($before);
    $this->assertSame('From A', reset($before)['title']);

    $this->assign('stark', 'header_menu', 'menu_b');
    $after = $this->menuLocations->items('header_menu', NULL, 'stark');
    $this->assertNotEmpty($after);
    $this->assertSame('From B', reset($after)['title']);
  }

  /**
   * The items() method returns the current content language's translation.
   *
   * The slot assignment names exactly one menu; a menu_link_content entity
   * is itself content-translatable, so the same assignment renders a
   * different title per language WITHOUT a second, config-level
   * menu-per-language mechanism — this is the model that replaced it.
   *
   * @covers ::items
   */
  public function testItemsReturnsTheContentLanguageTranslationOfTheMenu(): void {
    // Both languages need their OWN ConfigurableLanguage entity. 'en' only
    // ever existed as the implicit default before this — flipping
    // system.site:default_langcode to 'de' below would otherwise make 'en'
    // disappear from getLanguages() entirely (it was never a real entity,
    // only the synthesized default), leaving the site monolingual instead
    // of switching WHICH language is current.
    ConfigurableLanguage::createFromLangcode('en')->save();
    ConfigurableLanguage::createFromLangcode('de')->save();

    Menu::create(['id' => 'main', 'label' => 'Main'])->save();
    $link = MenuLinkContent::create([
      'menu_name' => 'main',
      'title' => 'Home',
      'link' => ['uri' => 'internal:/'],
      'enabled' => 1,
      'langcode' => 'en',
    ]);
    $link->save();
    $link->addTranslation('de', ['title' => 'Startseite'])->save();

    $this->assign('stark', 'header_menu', 'main');

    $english_items = $this->menuLocations->items('header_menu', NULL, 'stark');
    $this->assertNotEmpty($english_items);
    $this->assertSame('Home', reset($english_items)['title']);

    // Switch the negotiated content language to German. With no
    // negotiation methods configured beyond the site default, flipping
    // system.site:default_langcode and resetting the language manager is
    // what the negotiator falls back to.
    $this->config('system.site')->set('default_langcode', 'de')->save();
    \Drupal::languageManager()->reset();
    $this->assertSame(
      'de',
      \Drupal::languageManager()->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId(),
      'Test setup: the current content language must actually be German here.',
    );

    $german_items = $this->menuLocations->items('header_menu', NULL, 'stark');
    $this->assertNotEmpty($german_items);
    $this->assertSame('Startseite', reset($german_items)['title']);
  }

  /**
   * A max-age of 0 is never written to the render cache.
   *
   * A mocked EntityHelper stands in for the real menu tree builder here —
   * reproducing max-age 0 from a real menu tree needs a cache-context-free
   * uncacheable dependency the fixtures above don't have a natural source
   * for, while the service only cares that the metadata SAYS max-age 0.
   *
   * @covers ::items
   */
  public function testMaxAgeZeroIsNeverCached(): void {
    Menu::create(['id' => 'main', 'label' => 'Main'])->save();
    $this->assign('stark', 'header_menu', 'main');

    $entity_helper = $this->createMock(EntityHelper::class);
    $entity_helper->expects($this->exactly(2))->method('getMenu')
      ->with('main')
      ->willReturn([['id' => 'home', 'title' => 'Home']]);
    $entity_helper->method('collectCacheMetadata')
      ->willReturnCallback(static fn () => (new CacheableMetadata())->setCacheMaxAge(0));

    $menu_locations = $this->buildMenuLocations($entity_helper);

    // Called twice: if max-age 0 were cached, the second call would be a
    // hit and getMenu() would run only once — the mock's exactly(2)
    // expectation is the assertion.
    $menu_locations->items('header_menu', NULL, 'stark');
    $menu_locations->items('header_menu', NULL, 'stark');
  }

  /**
   * A render-cache hit does not rebuild the menu tree.
   *
   * Proves the "hit" tested above is a REAL hit, not merely an identical
   * rebuild: getMenu() is expected exactly once across two items() calls.
   *
   * @covers ::items
   */
  public function testCacheHitDoesNotCallGetMenuAgain(): void {
    Menu::create(['id' => 'main', 'label' => 'Main'])->save();
    $this->assign('stark', 'header_menu', 'main');

    $entity_helper = $this->createMock(EntityHelper::class);
    $entity_helper->expects($this->once())->method('getMenu')
      ->with('main')
      ->willReturn([['id' => 'home', 'title' => 'Home']]);
    $entity_helper->method('collectCacheMetadata')
      ->willReturn(new CacheableMetadata());

    $menu_locations = $this->buildMenuLocations($entity_helper);

    $first = $menu_locations->items('header_menu', NULL, 'stark');
    $second = $menu_locations->items('header_menu', NULL, 'stark');

    $this->assertSame($first, $second);
  }

  /**
   * Builds a MenuLocations with a mocked EntityHelper.
   *
   * Every other dependency is the real one from the container, so a test
   * can assert on how many times getMenu() ran.
   */
  protected function buildMenuLocations(EntityHelper $entity_helper): MenuLocations {
    return new MenuLocations(
      $this->container->get('config.factory'),
      $this->container->get('extension.list.theme'),
      $entity_helper,
      $this->container->get('variation_cache_factory'),
      $this->container->get('theme.manager'),
    );
  }

}
