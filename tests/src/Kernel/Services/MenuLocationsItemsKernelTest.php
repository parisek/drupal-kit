<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_kit\Kernel\Services;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\KernelTests\KernelTestBase;
use Drupal\drupal_kit\Services\MenuLocations;
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
   * Assigns a menu to a slot for the given theme/language in raw config.
   */
  protected function assign(string $theme, string $slot, string $langcode, string $menu_name): void {
    $config = $this->config('drupal_kit.menu_locations');
    $locations = $config->get('locations') ?? [];
    $locations[$theme][$slot][$langcode] = $menu_name;
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
    $this->assign('stark', 'header_menu', 'en', 'main');

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
    $this->assign('stark', 'header_menu', 'en', 'main');

    $first_collected = new CacheableMetadata();
    $first = $this->menuLocations->items('header_menu', $first_collected, 'stark');

    $second_collected = new CacheableMetadata();
    $second = $this->menuLocations->items('header_menu', $second_collected, 'stark');

    $this->assertSame($first, $second);
    $this->assertSame($first_collected->getCacheTags(), $second_collected->getCacheTags());
  }

}
