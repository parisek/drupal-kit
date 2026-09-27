<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_kit\Unit\Services;

use Drupal\Core\Cache\VariationCacheFactoryInterface;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\Theme\ActiveTheme;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\drupal_kit\Services\EntityHelper;
use Drupal\drupal_kit\Services\MenuLocations;
use PHPUnit\Framework\TestCase;

/**
 * Tests slot discovery and assignment resolution on MenuLocations.
 *
 * The items() method is covered by a kernel test — it needs the real menu
 * tree and variation-cache services. These tests cover the parts that only
 * need mocks.
 *
 * @coversDefaultClass \Drupal\drupal_kit\Services\MenuLocations
 * @group drupal_kit
 */
class MenuLocationsTest extends TestCase {

  /**
   * @covers ::slots
   */
  public function testSlotsReadsTheActiveThemeInfo(): void {
    $theme_list = $this->createMock(ThemeExtensionList::class);
    $theme_list->method('getExtensionInfo')
      ->with('arkero')
      ->willReturn([
        'menu_locations' => [
          'header_menu' => 'Header',
          'footer_menu1' => 'Footer (primary)',
        ],
      ]);

    $locations = new MenuLocations(
      $this->configFactory([]),
      $theme_list,
      $this->createMock(EntityHelper::class),
      $this->createMock(VariationCacheFactoryInterface::class),
      $this->themeManager('arkero'),
    );

    $this->assertSame(
      ['header_menu' => 'Header', 'footer_menu1' => 'Footer (primary)'],
      $locations->slots(),
    );
  }

  /**
   * @covers ::slots
   */
  public function testSlotsIsEmptyWhenThemeDeclaresNone(): void {
    $theme_list = $this->createMock(ThemeExtensionList::class);
    $theme_list->method('getExtensionInfo')->willReturn([]);

    $locations = new MenuLocations(
      $this->configFactory([]),
      $theme_list,
      $this->createMock(EntityHelper::class),
      $this->createMock(VariationCacheFactoryInterface::class),
      $this->themeManager('olivero'),
    );

    $this->assertSame([], $locations->slots());
  }

  /**
   * @covers ::menuName
   */
  public function testMenuNameResolvesTheAssignedMenu(): void {
    $config_data = [
      'drupal_kit.menu_locations' => [
        'locations' => [
          'arkero' => [
            'header_menu' => 'main',
          ],
        ],
      ],
    ];

    $locations = new MenuLocations(
      $this->configFactory($config_data),
      $this->createMock(ThemeExtensionList::class),
      $this->createMock(EntityHelper::class),
      $this->createMock(VariationCacheFactoryInterface::class),
      $this->themeManager('arkero'),
    );

    $this->assertSame('main', $locations->menuName('header_menu'));
  }

  /**
   * @covers ::menuName
   */
  public function testMenuNameIsNullWhenNothingIsAssigned(): void {
    $locations = new MenuLocations(
      $this->configFactory([
        'drupal_kit.menu_locations' => ['locations' => []],
      ]),
      $this->createMock(ThemeExtensionList::class),
      $this->createMock(EntityHelper::class),
      $this->createMock(VariationCacheFactoryInterface::class),
      $this->themeManager('arkero'),
    );

    $this->assertNull($locations->menuName('header_menu'));
  }

  /**
   * Builds a mocked config.factory returning the given data per config name.
   *
   * @param array<string, array<string, mixed>> $data
   *   Config name => raw data map.
   */
  protected function configFactory(array $data): ConfigFactoryInterface {
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturnCallback(function (string $name) use ($data) {
      $config = $this->createMock(Config::class);
      $values = $data[$name] ?? [];
      $config->method('get')->willReturnCallback(
        static function (string $key = '') use ($values) {
          if ($key === '') {
            return $values;
          }
          $value = $values;
          foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
              return NULL;
            }
            $value = $value[$part];
          }
          return $value;
        },
      );
      return $config;
    });
    return $factory;
  }

  /**
   * Builds a mocked theme manager whose active theme has the given name.
   */
  protected function themeManager(string $active_theme_name): ThemeManagerInterface {
    $active_theme = $this->createMock(ActiveTheme::class);
    $active_theme->method('getName')->willReturn($active_theme_name);

    $manager = $this->createMock(ThemeManagerInterface::class);
    $manager->method('getActiveTheme')->willReturn($active_theme);
    return $manager;
  }

  /**
   * A NULL $theme resolves the ACTIVE theme, not the site's default theme.
   *
   * The two differ on an admin route (a non-default admin theme active) or
   * under multi-theme negotiation — the service must read the theme that is
   * actually rendering, not always the site's configured default. The admin
   * FORM is the one place that deliberately reads the default theme instead
   * — see MenuLocationsForm's own docblock.
   *
   * @covers ::slots
   * @covers ::menuName
   */
  public function testNullThemeResolvesTheActiveThemeNotTheSiteDefault(): void {
    $theme_list = $this->createMock(ThemeExtensionList::class);
    $theme_list->method('getExtensionInfo')
      ->with('admin_theme')
      ->willReturn(['menu_locations' => ['header_menu' => 'Header']]);

    $locations = new MenuLocations(
      $this->configFactory([
        'drupal_kit.menu_locations' => [
          'locations' => ['admin_theme' => ['header_menu' => 'admin-menu']],
        ],
      ]),
      $theme_list,
      $this->createMock(EntityHelper::class),
      $this->createMock(VariationCacheFactoryInterface::class),
      // The active theme is 'admin_theme' — a different theme than a site's
      // usual default, e.g. 'arkero' — and nothing here ever reads
      // system.theme:default.
      $this->themeManager('admin_theme'),
    );

    $this->assertSame(['header_menu' => 'Header'], $locations->slots());
    $this->assertSame('admin-menu', $locations->menuName('header_menu'));
  }

}
