<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_kit\Unit\Services;

use Drupal\Core\Cache\VariationCacheFactoryInterface;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\Language\Language;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\drupal_kit\Services\EntityHelper;
use Drupal\drupal_kit\Services\MenuLocations;
use PHPUnit\Framework\TestCase;

/**
 * Tests slot discovery and per-language resolution on MenuLocations.
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
  public function testSlotsReadsTheDefaultThemeInfo(): void {
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
      $this->configFactory(['system.theme' => ['default' => 'arkero']]),
      $theme_list,
      $this->createMock(LanguageManagerInterface::class),
      $this->createMock(EntityHelper::class),
      $this->createMock(VariationCacheFactoryInterface::class),
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
      $this->configFactory(['system.theme' => ['default' => 'olivero']]),
      $theme_list,
      $this->createMock(LanguageManagerInterface::class),
      $this->createMock(EntityHelper::class),
      $this->createMock(VariationCacheFactoryInterface::class),
    );

    $this->assertSame([], $locations->slots());
  }

  /**
   * @covers ::menuName
   */
  public function testMenuNameResolvesForRequestedLanguage(): void {
    $config_data = [
      'system.theme' => ['default' => 'arkero'],
      'drupal_kit.menu_locations' => [
        'locations' => [
          'arkero' => [
            'header_menu' => ['cs' => 'main', 'en' => 'main-en'],
          ],
        ],
      ],
    ];

    $locations = new MenuLocations(
      $this->configFactory($config_data),
      $this->createMock(ThemeExtensionList::class),
      $this->languageManager('cs'),
      $this->createMock(EntityHelper::class),
      $this->createMock(VariationCacheFactoryInterface::class),
    );

    $this->assertSame('main', $locations->menuName('header_menu', 'cs'));
    $this->assertSame('main-en', $locations->menuName('header_menu', 'en'));
  }

  /**
   * A missing language falls back to the default language's menu.
   *
   * A language with no assignment of its own falls back to the site's
   * default language's menu, rather than resolving to nothing.
   *
   * @covers ::menuName
   */
  public function testMenuNameFallsBackToDefaultLanguage(): void {
    $config_data = [
      'system.theme' => ['default' => 'arkero'],
      'drupal_kit.menu_locations' => [
        'locations' => [
          'arkero' => [
            'header_menu' => ['cs' => 'main'],
          ],
        ],
      ],
    ];

    $locations = new MenuLocations(
      $this->configFactory($config_data),
      $this->createMock(ThemeExtensionList::class),
      $this->languageManager('cs', 'cs'),
      $this->createMock(EntityHelper::class),
      $this->createMock(VariationCacheFactoryInterface::class),
    );

    // 'de' has no assignment; 'cs' is the default language's menu.
    $this->assertSame('main', $locations->menuName('header_menu', 'de'));
  }

  /**
   * @covers ::menuName
   */
  public function testMenuNameIsNullWhenNothingIsAssigned(): void {
    $locations = new MenuLocations(
      $this->configFactory([
        'system.theme' => ['default' => 'arkero'],
        'drupal_kit.menu_locations' => ['locations' => []],
      ]),
      $this->createMock(ThemeExtensionList::class),
      $this->languageManager('cs', 'cs'),
      $this->createMock(EntityHelper::class),
      $this->createMock(VariationCacheFactoryInterface::class),
    );

    $this->assertNull($locations->menuName('header_menu', 'cs'));
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
   * Builds a mocked language manager for the given current/default language.
   */
  protected function languageManager(string $current, ?string $default = NULL): LanguageManagerInterface {
    $manager = $this->createMock(LanguageManagerInterface::class);
    $manager->method('getCurrentLanguage')
      ->willReturn(new Language(['id' => $current]));
    $manager->method('getDefaultLanguage')
      ->willReturn(new Language(['id' => $default ?? $current]));
    return $manager;
  }

}
