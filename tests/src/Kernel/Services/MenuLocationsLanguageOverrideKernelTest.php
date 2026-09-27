<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_kit\Kernel\Services;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\KernelTests\KernelTestBase;
use Drupal\drupal_kit\Services\MenuLocations;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\system\Entity\Menu;

/**
 * Tests a per-language SLOT ASSIGNMENT through a native config override.
 *
 * `language` module alone is enough for this — `LanguageConfigFactoryOverride`
 * (the mechanism `\Drupal\Core\Config\ConfigFactory::get()` transparently
 * applies) is that module's own service. `config_translation` is a separate,
 * UI-only layer on top (the "Translate" tab and its form); writing the
 * override directly, the way this test does, is exactly what that form
 * would do on submit. See MenuLocations' own class docblock for the model.
 *
 * @coversDefaultClass \Drupal\drupal_kit\Services\MenuLocations
 * @group drupal_kit
 */
class MenuLocationsLanguageOverrideKernelTest extends KernelTestBase {

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
    // Both need their OWN entity — 'en' only ever existing as the implicit
    // default would make it vanish from getLanguages() the moment anything
    // else negotiates as current, turning the site silently monolingual.
    ConfigurableLanguage::createFromLangcode('en')->save();
    ConfigurableLanguage::createFromLangcode('de')->save();
    $this->menuLocations = $this->container->get('drupal_kit.menu_locations');
  }

  /**
   * A language override resolves only under its own language.
   *
   * The default-language assignment still resolves outside it.
   *
   * @covers ::items
   * @covers ::menuName
   */
  public function testLanguageOverrideResolvesUnderItsLanguageOnly(): void {
    Menu::create(['id' => 'main', 'label' => 'Main'])->save();
    Menu::create(['id' => 'main_de', 'label' => 'Main DE'])->save();
    MenuLinkContent::create([
      'menu_name' => 'main',
      'title' => 'Default menu item',
      'link' => ['uri' => 'internal:/'],
      'enabled' => 1,
    ])->save();
    MenuLinkContent::create([
      'menu_name' => 'main_de',
      'title' => 'German menu item',
      'link' => ['uri' => 'internal:/'],
      'enabled' => 1,
    ])->save();

    // The default-language assignment — the flat, always-present shape.
    $this->config('drupal_kit.menu_locations')
      ->set('locations.stark.header_menu', 'main')
      ->save();

    // The per-language override — what config_translation's own
    // "Translate" tab / MenuSelect element would write on submit.
    $this->container->get('language.config_factory_override')
      ->getOverride('de', 'drupal_kit.menu_locations')
      ->set('locations.stark.header_menu', 'main_de')
      ->save();

    $default_items = $this->menuLocations->items('header_menu', NULL, 'stark');
    $this->assertNotEmpty($default_items);
    $this->assertSame('Default menu item', reset($default_items)['title']);

    $this->container->get('language_manager')->setConfigOverrideLanguage(
      $this->container->get('language_manager')->getLanguage('de'),
    );

    $collected = new CacheableMetadata();
    $german_items = $this->menuLocations->items('header_menu', $collected, 'stark');
    $this->assertNotEmpty($german_items);
    $this->assertSame('German menu item', reset($german_items)['title']);
    $this->assertContains('languages:language_interface', $collected->getCacheContexts());

    // Switching the override language back off resolves the default
    // assignment again — the override is per-language, not sticky.
    $this->container->get('language_manager')->setConfigOverrideLanguage(NULL);
    $back_to_default = $this->menuLocations->items('header_menu', NULL, 'stark');
    $this->assertNotEmpty($back_to_default);
    $this->assertSame('Default menu item', reset($back_to_default)['title']);
  }

  /**
   * The language context is present whenever an override provider is active.
   *
   * That holds whether or not THIS config has an override yet.
   * `LanguageConfigFactoryOverride::getCacheableMetadata()` adds
   * `languages:language_interface` based on whether a config override
   * language is set at all, not on whether a `drupal_kit.menu_locations`
   * override specifically exists. That is deliberate on core's part: a
   * translator could add one later, and the cache must already vary by
   * language to accommodate that without a full cache clear.
   *
   * @covers ::menuName
   */
  public function testLanguageContextIsPresentWheneverLanguageModuleIsActive(): void {
    $this->config('drupal_kit.menu_locations')
      ->set('locations.stark.header_menu', 'main')
      ->save();

    $collected = new CacheableMetadata();
    $this->menuLocations->menuName('header_menu', 'stark', $collected);

    $this->assertContains('languages:language_interface', $collected->getCacheContexts());
  }

}
